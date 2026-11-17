<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Builder;
use App\Models\User;
use App\Models\Video;
use App\Models\YoutubeChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EditorialFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withHeader('Origin', 'http://localhost:3000');
    }

    private function editor(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->mfaMethod()->create(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'confirmed_at' => now()]);
        $this->actingAs($user)->withSession(['mfa_verified_at' => now()->timestamp]);
    }

    public function test_categories_match_each_edition_in_editorial_order_without_deleting_existing_taxonomy(): void
    {
        foreach (config('editorial.categories') as $edition => $names) {
            $response = $this->getJson('/api/v1/categories?edition='.$edition)->assertOk();
            $this->assertSame($names, array_column($response->json('data'), 'name'));
        }
        $this->getJson('/api/v1/categories?edition=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/categories/fintech')->assertOk();
        $this->seed();
        $this->assertDatabaseHas('categories', ['slug' => 'community']);
        $this->assertDatabaseHas('categories', ['slug' => 'research']);
    }

    public function test_editor_picks_cannot_be_reenabled_and_legacy_configuration_is_preserved(): void
    {
        DB::table('homepage_sections')->insert(['key' => 'picks', 'position' => 4, 'visible' => true, 'pinned_ids' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $home = $this->getJson('/api/v1/home')->assertOk()->assertJsonMissingPath('data.picks')->json('data.sections');
        $this->assertNotContains('picks', array_column($home, 'key'));
        $this->editor();
        $this->assertNotContains('picks', array_column($this->getJson('/api/v1/console/homepage')->assertOk()->json('data'), 'key'));
        $this->putJson('/api/v1/console/homepage', ['sections' => [['key' => 'picks', 'visible' => true]]])->assertUnprocessable();
        $this->assertDatabaseHas('homepage_sections', ['key' => 'picks', 'visible' => true]);
    }

    public function test_video_formats_are_editorial_metadata_and_filter_before_public_pagination(): void
    {
        $this->editor();
        $ids = [];
        $channel = YoutubeChannel::create(['provider_id' => 'UC'.str_repeat('a', 22), 'active' => true]);
        foreach (['interview', 'podcast', 'documentary'] as $i => $format) {
            $video = Video::forceCreate(['youtube_channel_id' => $channel->id, 'youtube_id' => 'LocalVideo'.$i, 'provider_title' => 'Provider '.$format, 'status' => 'published', 'available' => true, 'embeddable' => true, 'edition' => 'both']);
            $this->patchJson('/api/v1/console/videos/'.$video->id, ['format' => $format])->assertOk()->assertJsonPath('data.format', $format);
            $ids[$format] = $video->id;
        }
        $unclassified = Video::forceCreate(['youtube_channel_id' => $channel->id, 'youtube_id' => 'LocalUncl01', 'provider_title' => 'Unclassified provider video', 'status' => 'published', 'available' => true, 'embeddable' => true]);
        Video::forceCreate(['youtube_channel_id' => $channel->id, 'youtube_id' => 'LocalPriv01', 'provider_title' => 'Private video', 'format' => 'podcast', 'status' => 'published', 'available' => false, 'embeddable' => true]);
        foreach ($ids as $format => $id) {
            $this->getJson('/api/v1/videos?edition=Australia&format='.$format.'&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.format', $format)->assertJsonPath('meta.total', 1);
        }
        $this->getJson('/api/v1/videos?format=invalid')->assertUnprocessable();
        $this->patchJson('/api/v1/console/videos/'.$unclassified->id, ['format' => 'invalid'])->assertUnprocessable();
        $this->assertNull($unclassified->fresh()->format);
        $this->assertSame('Provider podcast', Video::findOrFail($ids['podcast'])->provider_title);
    }

    public function test_month_feature_only_returns_current_published_consenting_builders(): void
    {
        foreach ([['Current', 'published', true, now()->subDay(), now()->addDay()], ['Future', 'published', true, now()->addDay(), now()->addDays(2)], ['Expired', 'published', true, now()->subDays(2), now()->subDay()], ['Draft', 'draft', true, null, null], ['No consent', 'published', false, null, null]] as [$name, $status, $consent, $from, $until]) {
            Builder::forceCreate(['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'classification' => 'Entrepreneur', 'status' => $status, 'feature' => 'month', 'feature_from' => $from, 'feature_until' => $until, 'consent_at' => $consent ? now() : null]);
        }
        $this->getJson('/api/v1/builders?feature=month')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Current');
    }

    public function test_edition_attributions_persist_independently_and_remain_plain_text(): void
    {
        $this->editor();
        $this->putJson('/api/v1/console/settings', ['footerAustralia' => 'Powered by TechPro Consulting Pty Ltd', 'footerNigeria' => 'Powered by TechPro Nigeria'])->assertOk();
        $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('data.footerAustralia', 'Powered by TechPro Consulting Pty Ltd')->assertJsonPath('data.footerNigeria', 'Powered by TechPro Nigeria');
        $this->putJson('/api/v1/console/settings', ['footerAustralia' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->getJson('/api/v1/settings/public')->assertJsonPath('data.footerNigeria', 'Powered by TechPro Nigeria');
    }
}
