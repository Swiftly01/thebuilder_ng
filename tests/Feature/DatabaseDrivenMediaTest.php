<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\MediaSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DatabaseDrivenMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_is_repeatable_and_preserves_admin_edits(): void
    {
        Storage::fake('public');
        $this->seed(MediaSeeder::class);
        $this->assertSame(8, Media::count());
        $media = Media::firstOrFail();
        $media->update(['alt' => 'Admin reviewed description', 'credit' => 'Admin reviewed credit']);
        $this->seed(MediaSeeder::class);
        $this->assertSame(8, Media::count());
        $this->assertSame('Admin reviewed description', $media->fresh()->alt);
        foreach (Media::all() as $item) {
            Storage::disk('public')->assertExists($item->path);
            $this->assertSame($item->size, strlen(Storage::disk('public')->get($item->path)));
        }
        Storage::disk('public')->put($media->path, 'tampered bytes');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Existing seed image differs');
        $this->seed(MediaSeeder::class);
    }

    public function test_failed_storage_write_does_not_create_a_false_media_record(): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturn(false);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);
        try {
            $this->seed(MediaSeeder::class);
            $this->fail('Storage failure should throw.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Could not store seed image', $error->getMessage());
        }
        $this->assertSame(0, Media::count());
    }

    public function test_demo_content_is_repeatable_and_keeps_existing_media_choices(): void
    {
        Storage::fake('public');
        $author = User::factory()->create();
        $existing = Media::create(['path' => 'images/existing.webp', 'mime' => 'image/webp', 'size' => 1, 'alt' => 'Existing approved image']);
        $article = Article::create(['title' => 'Approved content', 'slug' => 'approved-content', 'author_id' => $author->id, 'media_id' => $existing->id]);
        $this->seed(DemoContentSeeder::class);
        $counts = [Media::count(), Article::count(), DB::table('builders')->count(), DB::table('startups')->count()];
        $this->seed(DemoContentSeeder::class);
        $this->assertSame($counts, [Media::count(), Article::count(), DB::table('builders')->count(), DB::table('startups')->count()]);
        $this->assertSame([9, 16, 5, 3], $counts);
        $this->assertSame($existing->id, $article->fresh()->media_id);
        $this->assertSame('suspended', User::where('email', 'demo-editor@example.test')->firstOrFail()->status);
        $this->getJson('/api/v1/home?edition=Nigeria')->assertOk()->assertJsonPath('data.lead.imageUrl', fn ($url) => str_contains($url, '/storage/seed/generated/'));
        $this->getJson('/api/v1/builders')->assertOk()->assertJsonPath('data.0.imageAlt', fn ($alt) => str_contains($alt, 'AI-generated'));
        $this->getJson('/api/v1/startups')->assertOk()->assertJsonPath('data.0.imageUrl', fn ($url) => str_contains($url, '/storage/seed/generated/'));
    }

    public function test_demo_content_refuses_production_before_modifying_the_database(): void
    {
        app()->detectEnvironment(fn () => 'production');
        DB::enableQueryLog();
        try {
            app(DemoContentSeeder::class)->run();
            $this->fail('Production must refuse demo content.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('restricted to the isolated local preview', $error->getMessage());
        }
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_admin_can_replace_page_images_and_referenced_media_cannot_be_deleted(): void
    {
        Storage::fake('public');
        $this->seed();
        $this->seed(MediaSeeder::class);
        $first = Media::firstOrFail();
        $second = Media::where('id', '!=', $first->id)->firstOrFail();
        $this->getJson('/api/v1/console/media/'.$first->id)->assertUnauthorized();
        $user = User::factory()->create();
        $user->assignRole('registered_user');
        $this->actingAs($user)->getJson('/api/v1/console/media/'.$first->id)->assertForbidden();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->mfaMethod()->create(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'confirmed_at' => now()]);
        $this->actingAs($admin)->withSession(['mfa_verified_at' => now()->timestamp])->withHeader('Origin', 'http://localhost:3000');
        $this->getJson('/api/v1/console/media/'.$first->id)->assertOk()->assertJsonPath('data.alt', $first->alt);
        $this->putJson('/api/v1/console/settings', ['pageImages' => ['about' => $first->id]])->assertOk();
        $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('data.pageMedia.about.id', $first->id);
        $this->deleteJson('/api/v1/console/media/'.$first->id)->assertConflict();
        $this->putJson('/api/v1/console/settings', ['pageImages' => ['about' => $second->id]])->assertOk();
        $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('data.pageMedia.about.id', $second->id);
        $this->putJson('/api/v1/console/settings', ['pageImages' => ['about' => 'missing']])->assertUnprocessable();
        $this->putJson('/api/v1/console/settings', ['pageImages' => ['unknownSlot' => $second->id]])->assertUnprocessable();
        $this->putJson('/api/v1/console/settings', ['pageImages' => ['about' => null]])->assertOk();
        $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('data.pageMedia.about', null);
    }
}
