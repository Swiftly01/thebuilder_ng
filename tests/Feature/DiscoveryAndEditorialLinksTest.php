<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Builder;
use App\Models\Nomination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class DiscoveryAndEditorialLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
        $this->withHeader('Origin', 'http://localhost:3000');
    }

    private function editor(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->mfaMethod()->create(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'confirmed_at' => now()]);
        $this->actingAs($user)->withSession(['mfa_verified_at' => now()->timestamp]);

        return $user;
    }

    public function test_nomination_creates_one_linked_draft_and_requires_a_published_story_for_final_status(): void
    {
        $this->editor();
        $body = ['type' => 'Builder', 'name' => 'Nominated builder', 'industry' => 'Technology', 'country' => 'Nigeria', 'reason' => 'A meaningful nomination reason with enough details for an editor.', 'submitterName' => 'Reader', 'submitterEmail' => 'reader@example.test', 'relationship' => 'Colleague', 'consent' => true];
        $reference = $this->withHeader('Idempotency-Key', 'nomination-linked-draft')->postJson('/api/v1/nominations', $body)->assertCreated()->json('data.reference');
        $nomination = Nomination::where('reference', $reference)->firstOrFail();
        $url = '/api/v1/console/nominations/'.$nomination->id.'/draft';
        $id = $this->postJson($url)->assertCreated()->json('data.id');
        $this->postJson($url)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('articles', 1);
        $this->assertDatabaseHas('articles', ['id' => $id, 'status' => 'draft']);
        $this->assertSame($id, $nomination->fresh()->article_id);
        $this->getJson('/api/v1/articles')->assertJsonCount(0, 'data');
    }

    public function test_diaspora_is_editorially_classified_and_filtered_by_region(): void
    {
        $this->editor();
        foreach ([['Local builder', false, 'Nigeria', 'Africa'], ['Diaspora builder', true, 'Australia', 'Oceania']] as [$name,$diaspora,$country,$region]) {
            $this->postJson('/api/v1/console/builders', ['name' => $name, 'classification' => 'Professional', 'country' => $country, 'region' => $region, 'status' => 'published', 'consentAt' => '2026-10-01', 'consentMethod' => 'Written permission', 'isDiaspora' => $diaspora])->assertCreated();
        }
        $this->getJson('/api/v1/builders?diaspora=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Diaspora builder');
        $this->getJson('/api/v1/builders?diaspora=1&country=Nigeria')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/home')->assertJsonCount(1, 'data.builders');
    }

    public function test_startup_founders_are_persisted_and_unpublished_profiles_never_leak(): void
    {
        $this->editor();
        $published = Builder::forceCreate(['name' => 'Consenting founder', 'slug' => 'consenting-founder', 'classification' => 'Startup Founder', 'status' => 'published', 'consent_at' => now(), 'consent_method' => 'Written permission']);
        $draft = Builder::forceCreate(['name' => 'Private founder', 'slug' => 'private-founder', 'classification' => 'Startup Founder', 'status' => 'draft']);
        $startup = $this->postJson('/api/v1/console/startups', ['name' => 'A real startup', 'oneLiner' => 'Building useful infrastructure.', 'status' => 'published', 'founders' => [$published->id, $draft->id]])->assertCreated()->json('data');
        $this->getJson('/api/v1/startups/'.$startup['slug'])->assertOk()->assertJsonCount(1, 'data.founders')->assertJsonPath('data.founders.0.name', 'Consenting founder');
        $this->getJson('/api/v1/console/startups')->assertJsonCount(2, 'data.0.founders');
    }

    public function test_homepage_pins_keep_order_and_public_reads_exclude_drafts(): void
    {
        $user = $this->editor();
        $published = Article::forceCreate(['title' => 'Pinned public story', 'slug' => 'pinned-public', 'summary' => 'Public summary', 'body' => '<p>Public body</p>', 'author_id' => $user->id, 'status' => 'published', 'published_at' => now()->subMinute()]);
        $draft = Article::create(['title' => 'Private draft', 'slug' => 'private-home-draft', 'author_id' => $user->id]);
        $this->putJson('/api/v1/console/homepage', ['sections' => [['key' => 'lead', 'visible' => true, 'pinnedIds' => [$draft->id, $published->id]]]])->assertOk();
        $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.lead.id', $published->id)->assertJsonCount(0, 'data.secondary')->assertJsonMissingPath('data.picks');
        $this->assertDatabaseHas('homepage_sections', ['key' => 'lead', 'pinned_ids' => $this->castAsJson([$draft->id, $published->id])]);
    }

    public function test_analytics_consent_is_required_and_duplicate_views_are_atomic(): void
    {
        $user = $this->editor();
        $article = Article::forceCreate(['title' => 'Tracked public story', 'slug' => 'tracked-public', 'author_id' => $user->id, 'status' => 'published', 'published_at' => now()->subMinute()]);
        $event = ['articleId' => $article->id, 'type' => 'view', 'sessionId' => 'test-session-1234567890'];
        $this->postJson('/api/v1/track/events', $event)->assertUnprocessable();
        $this->postJson('/api/v1/track/events', $event + ['consent' => true])->assertNoContent();
        $this->postJson('/api/v1/track/events', $event + ['consent' => true])->assertNoContent();
        $this->assertSame(1, $article->fresh()->view_count);
        $this->assertSame(1, DB::table('analytics_events')->count());
    }
}
