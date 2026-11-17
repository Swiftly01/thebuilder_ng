<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Editorial\Workflow;
use App\Models\Article;
use App\Models\Media;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ReleaseRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['builder.ssr_secret' => str_repeat('s', 64), 'builder.ssr_requests_per_minute' => 200]);
    }

    /** @return array<string, string> */
    private function signed(string $path, ?int $at = null): array
    {
        $timestamp = (string) ($at ?? now()->timestamp);

        return ['X-Builder-SSR-Timestamp' => $timestamp, 'X-Builder-SSR-Signature' => hash_hmac('sha256', $timestamp."\nGET\n".$path, config('builder.ssr_secret'))];
    }

    public function test_signed_ssr_reads_have_a_separate_bounded_budget_and_cannot_be_spoofed(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/settings/public')->assertOk();
        }
        $this->getJson('/api/v1/home')->assertStatus(429);
        $path = '/api/v1/settings/public';
        for ($i = 0; $i < 200; $i++) {
            $this->getJson($path, $this->signed($path))->assertOk();
        }
        $this->getJson($path, $this->signed($path))->assertStatus(429);
        $this->getJson($path, $this->signed($path, now()->subMinute()->timestamp))->assertUnauthorized();
        $this->getJson($path.'?edition=Australia', $this->signed($path))->assertUnauthorized();
        $this->getJson($path, $this->signed($path) + ['Cookie' => 'forged=value'])->assertUnauthorized();
        $this->getJson('/api/v1/console/content', $this->signed('/api/v1/console/content'))->assertUnauthorized();
    }

    public function test_changed_published_slugs_resolve_to_the_latest_published_target_only(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $article = Article::forceCreate(['title' => 'Original', 'slug' => 'original', 'summary' => 'Summary', 'body' => '<p>Body</p>', 'author_id' => $user->id, 'status' => 'published', 'published_at' => now()->subMinute()]);
        $workflow = app(Workflow::class);
        $article = $workflow->save($article, ['slug' => 'second'], $user, (string) $article->version);
        $article = $workflow->save($article, ['slug' => 'latest'], $user, (string) $article->version);
        $this->getJson('/api/v1/articles/original')->assertStatus(301)->assertJsonPath('data.redirect', '/stories/latest');
        $this->getJson('/api/v1/articles/second')->assertStatus(301)->assertJsonPath('data.redirect', '/stories/latest');
        $article->forceFill(['status' => 'draft'])->save();
        $this->getJson('/api/v1/articles/original')->assertNotFound();
        foreach (['unsafe' => 'https://evil.example', 'loop' => '/stories/loop'] as $slug => $target) {
            DB::table('redirects')->insert(['from_path' => '/stories/'.$slug, 'to_path' => $target, 'created_at' => now(), 'updated_at' => now()]);
            $this->getJson('/api/v1/articles/'.$slug)->assertNotFound();
        }
        $article->forceFill(['status' => 'published'])->save();
        $workflow->save($article, ['slug' => 'olúṣọlá-builder'], $user, (string) $article->version);
        $this->getJson('/api/v1/articles/original')->assertStatus(301)->assertJsonPath('data.redirect', '/stories/olúṣọlá-builder');
        $this->getJson('/api/v1/articles/'.rawurlencode('olúṣọlá-builder'))->assertOk();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->mfaMethod()->create(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'confirmed_at' => now()]);
        $this->withHeader('Origin', 'http://localhost:3000')->actingAs($user, 'web')->withSession(['mfa_verified_at' => now()->timestamp]);

        return $user;
    }

    public function test_failed_write_never_creates_a_media_record_or_returns_a_url(): void
    {
        $this->admin();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);
        $this->postJson('/api/v1/console/media', ['file' => UploadedFile::fake()->image('test.png'), 'alt' => 'Alternative text'])->assertStatus(503);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_failed_delete_preserves_the_media_record_for_retry(): void
    {
        $user = $this->admin();
        $media = Media::create(['path' => 'images/test.webp', 'mime' => 'image/webp', 'size' => 20, 'alt' => 'Test', 'uploaded_by' => $user->id]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);
        $this->deleteJson('/api/v1/console/media/'.$media->id)->assertStatus(503);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_storage_exception_returns_unavailable_without_a_phantom_record(): void
    {
        $this->admin();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('Isolated disk permission failure'));
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);
        $this->postJson('/api/v1/console/media', ['file' => UploadedFile::fake()->image('test.png'), 'alt' => 'Alternative text'])->assertStatus(503);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_database_failure_rolls_back_media_and_attempts_file_cleanup(): void
    {
        $this->admin();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);
        Media::creating(fn () => throw new \RuntimeException('Isolated database failure'));
        try {
            $this->postJson('/api/v1/console/media', ['file' => UploadedFile::fake()->image('test.png'), 'alt' => 'Alternative text'])->assertStatus(500);
            $this->assertDatabaseCount('media', 0);
        } finally {
            Media::flushEventListeners();
        }
    }

    public function test_invalid_preflight_stops_before_database_access(): void
    {
        config(['app.debug' => true]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->artisan('builder:release-check --preflight')
            ->expectsOutput('Preflight rejected configuration before database access or writes.')
            ->assertFailed();
        $this->assertSame([], DB::getQueryLog());
    }
}
