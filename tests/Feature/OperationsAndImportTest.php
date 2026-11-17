<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Administration\SnapshotImport;
use App\Models\Article;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class OperationsAndImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withHeader('Origin', 'http://localhost:3000');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->mfaMethod()->create(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'confirmed_at' => now()]);
        $this->actingAs($user, 'web')->withSession(['mfa_verified_at' => now()->timestamp]);

        return $user;
    }

    public function test_opportunities_validate_persist_expire_and_require_permissions(): void
    {
        $this->postJson('/api/v1/console/opportunities', [])->assertUnauthorized();
        $this->admin();
        $data = ['title' => 'Climate fellowship', 'slug' => 'climate-fellowship', 'organisation' => 'Example Foundation', 'type' => 'fellowship', 'location' => 'Remote', 'remote' => true, 'description' => 'A fellowship for climate researchers.', 'applyUrl' => 'https://example.org/apply', 'deadline' => today()->addWeek()->toDateString(), 'status' => 'draft'];
        $id = $this->postJson('/api/v1/console/opportunities', $data)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/opportunities/climate-fellowship')->assertNotFound();
        $this->putJson('/api/v1/console/opportunities/'.$id, $data + [])->assertOk();
        $data['status'] = 'published';
        $this->putJson('/api/v1/console/opportunities/'.$id, $data)->assertOk();
        $this->getJson('/api/v1/opportunities/climate-fellowship')->assertOk()->assertJsonPath('data.organisation', 'Example Foundation');
        $this->getJson('/api/v1/opportunities?remote=1&deadlineDays=7&type=fellowship')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/opportunities?remote=0')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/opportunities?deadlineDays=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/opportunities?deadlineDays=-1')->assertUnprocessable();
        $this->putJson('/api/v1/console/opportunities/'.$id, array_replace($data, ['applyUrl' => 'javascript:alert(1)']))->assertUnprocessable();
        $this->travel(8)->days();
        $this->getJson('/api/v1/opportunities/climate-fellowship')->assertNotFound();
    }

    public function test_snapshot_import_rehearses_rolls_back_replays_and_preserves_relationships(): void
    {
        $snapshot = ['version' => 1, 'source' => 'reviewed-export', 'records' => [
            ['entity' => 'users', 'sourceId' => 'old-user-7', 'data' => ['name' => 'Original contributor', 'email' => 'source@example.test', 'passwordHash' => Hash::make('OriginalPassword42'), 'verifiedAt' => now()->subDay()->toISOString()]],
            ['entity' => 'categories', 'sourceId' => 'old-category-9', 'data' => ['name' => 'Business', 'slug' => 'business']],
            ['entity' => 'articles', 'sourceId' => 'old-article-12', 'data' => ['title' => 'Preserved editorial story', 'slug' => 'preserved-editorial-story', 'summary' => 'Original summary', 'body' => '<p>Original body</p><script>bad()</script>', 'authorSourceId' => 'old-user-7', 'categorySourceId' => 'old-category-9', 'status' => 'published', 'publishedAt' => now()->subDay()->toISOString(), 'edition' => 'Nigeria']],
            ['entity' => 'bookmarks', 'sourceId' => 'old-bookmark-1', 'data' => ['userSourceId' => 'old-user-7', 'articleSourceId' => 'old-article-12']],
        ]];
        $importer = app(SnapshotImport::class);
        $this->assertSame(['created' => 4, 'replayed' => 0], $importer->run($snapshot));
        $this->assertDatabaseCount('articles', 0);
        $this->assertDatabaseCount('legacy_import_map', 0);
        $this->assertSame(['created' => 4, 'replayed' => 0], $importer->run($snapshot, true));
        $this->assertSame(['created' => 0, 'replayed' => 4], $importer->run($snapshot, true));
        $user = User::where('email', 'source@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('OriginalPassword42', $user->password));
        $this->assertSame(['registered_user'], $user->getRoleNames()->all());
        $story = Article::firstOrFail();
        $this->assertSame($user->id, $story->author_id);
        $this->assertSame(Category::where('slug', 'business')->firstOrFail()->id, $story->category_id);
        $this->assertStringNotContainsString('<script>', $story->body);
        $this->assertDatabaseHas('bookmarks', ['user_id' => $user->id, 'article_id' => $story->id]);
        $snapshot['records'][2]['data']['title'] = 'Unexpected mutation';
        try {
            $importer->run($snapshot, true);
            $this->fail('Changed source records must not overwrite existing targets.');
        } catch (ValidationException) {
        }
        $this->assertSame('Preserved editorial story', $story->fresh()->title);
    }

    public function test_snapshot_with_broken_reference_rolls_back_all_records(): void
    {
        $snapshot = ['version' => 1, 'source' => 'bad-reference', 'records' => [
            ['entity' => 'users', 'sourceId' => '1', 'data' => ['name' => 'Rollback reader', 'email' => 'rollback@example.test']],
            ['entity' => 'bookmarks', 'sourceId' => '2', 'data' => ['userSourceId' => '1', 'articleSourceId' => 'absent']],
        ]];
        try {
            app(SnapshotImport::class)->run($snapshot, true);
            $this->fail('Broken imports must fail.');
        } catch (ValidationException) {
        }
        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
        $this->assertDatabaseCount('legacy_import_map', 0);
    }

    public function test_grace_period_purges_reader_data_and_anonymizes_editorial_attribution(): void
    {
        $reader = User::factory()->create(['status' => 'deactivated', 'deletion_requested_at' => now()->subDays(31)]);
        $reader->profile()->create(['biography' => 'Private personal data']);
        $writer = User::factory()->create(['status' => 'deactivated', 'deletion_requested_at' => now()->subDays(31)]);
        $article = Article::create(['title' => 'Retained journalism', 'slug' => 'retained-journalism', 'author_id' => $writer->id]);
        $recent = User::factory()->create(['status' => 'deactivated', 'deletion_requested_at' => now()->subDays(29)]);
        $this->artisan('builder:purge-accounts')->assertSuccessful();
        $this->assertDatabaseMissing('users', ['id' => $reader->id]);
        $this->assertDatabaseMissing('profiles', ['user_id' => $reader->id]);
        $this->assertSame('Former contributor', $writer->fresh()->name);
        $this->assertStringEndsWith('@deleted.invalid', $writer->fresh()->email);
        $this->assertDatabaseHas('articles', ['id' => $article->id]);
        $this->assertSame($recent->email, $recent->fresh()->email);
    }

    public function test_published_content_cannot_be_edited_by_a_content_editor_without_review(): void
    {
        $editor = User::factory()->create();
        $editor->assignRole('content_editor');
        $editor->mfaMethod()->create(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'confirmed_at' => now()]);
        $article = Article::forceCreate(['author_id' => $editor->id, 'title' => 'Published original', 'slug' => 'published-original', 'summary' => 'Original', 'body' => '<p>Original body</p>', 'status' => 'published', 'published_at' => now()]);
        $this->actingAs($editor, 'web')->withSession(['mfa_verified_at' => now()->timestamp]);
        $this->getJson('/api/v1/console/content/'.$article->id)->assertOk();
        $this->withHeader('If-Match', '1')->patchJson('/api/v1/console/content/'.$article->id, ['title' => 'Bypass review'])->assertForbidden();
        $this->assertSame('Published original', $article->fresh()->title);
    }

    public function test_category_cycles_are_rejected_and_reseeding_preserves_homepage_configuration(): void
    {
        $this->admin();
        $parent = Category::create(['name' => 'Parent', 'slug' => 'parent']);
        $child = Category::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $parent->id]);
        $this->putJson('/api/v1/console/taxonomy/categories', ['id' => $parent->id, 'name' => 'Parent', 'slug' => 'parent', 'parent_id' => $child->id])->assertUnprocessable();
        DB::table('homepage_sections')->where('key', 'lead')->update(['visible' => false, 'position' => 10]);
        $this->seed();
        $this->assertDatabaseHas('homepage_sections', ['key' => 'lead', 'visible' => false, 'position' => 10]);
    }
}
