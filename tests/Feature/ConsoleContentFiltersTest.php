<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConsoleContentFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $editor = User::factory()->create();
        $editor->assignRole('admin');
        $editor->mfaMethod()->create(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'confirmed_at' => now()]);
        $this->actingAs($editor)->withSession(['mfa_verified_at' => now()->timestamp])->withHeader('Origin', 'http://localhost:3000');
    }

    public function test_search_and_filters_run_before_pagination_and_match_authors_without_bypassing_status(): void
    {
        $author = User::factory()->create(['name' => 'Distinctive Author']);
        $other = User::factory()->create(['name' => 'Another Writer']);
        $older = Article::forceCreate(['title' => 'Older target story', 'slug' => 'older-target', 'author_id' => $author->id, 'edition' => 'Australia', 'status' => 'review', 'created_at' => now()->subDay()]);
        for ($i = 0; $i < 21; $i++) {
            Article::forceCreate(['title' => 'Newer filler '.$i, 'slug' => 'newer-filler-'.$i, 'author_id' => $other->id, 'edition' => 'Nigeria', 'status' => 'draft']);
        }
        Article::forceCreate(['title' => 'Private other status', 'slug' => 'other-status', 'author_id' => $author->id, 'edition' => 'Australia', 'status' => 'draft']);

        $this->getJson('/api/v1/console/content')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/console/content?q=Older&status=review&edition=Australia')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $older->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/console/content?q=Distinctive&status=review&edition=Australia')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $older->id);
        $this->getJson('/api/v1/console/content?q=Distinctive&status=review&edition=Nigeria')->assertOk()->assertJsonCount(0, 'data');
        $first = $this->getJson('/api/v1/console/content?status=draft&edition=Nigeria&per_page=10')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 21)->json();
        $this->assertStringContainsString('status=draft', $first['links']['next']);
        $second = $this->getJson('/api/v1/console/content?status=draft&edition=Nigeria&per_page=10&page=2')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 21)->json('data');
        $this->assertSame([], array_values(array_intersect(array_column($first['data'], 'id'), array_column($second, 'id'))));
        $this->getJson('/api/v1/console/content?q=0&status=draft&edition=Nigeria')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_invalid_filters_are_rejected_and_readers_cannot_search_drafts(): void
    {
        $this->getJson('/api/v1/console/content?edition=invalid')->assertUnprocessable()->assertJsonValidationErrors('edition');
        $this->getJson('/api/v1/console/content?status=invalid')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/v1/console/content?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
        $reader = User::factory()->create();
        $reader->assignRole('registered_user');
        $this->actingAs($reader)->getJson('/api/v1/console/content?q=private')->assertForbidden();
    }

    public function test_content_editors_only_list_stories_they_can_open(): void
    {
        $editor = User::factory()->create(['name' => 'Restricted author']);
        $editor->assignRole('content_editor');
        $editor->mfaMethod()->create(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'confirmed_at' => now()]);
        $other = User::factory()->create(['name' => 'Unrelated author']);
        $own = Article::create(['title' => 'Shared search term own draft', 'slug' => 'own-draft', 'author_id' => $editor->id]);
        $private = Article::create(['title' => 'Shared search term private draft', 'slug' => 'private-draft', 'author_id' => $other->id]);
        $this->actingAs($editor)->withSession(['mfa_verified_at' => now()->timestamp]);
        $this->getJson('/api/v1/console/content?q=Shared')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/console/content?q=Unrelated')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/console/content/'.$private->id)->assertForbidden();
    }
}
