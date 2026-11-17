<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Editorial\Workflow;
use App\Domain\Videos\YoutubeSync;
use App\Jobs\SyncYoutubeChannel;
use App\Models\Article;
use App\Models\Media;
use App\Models\User;
use App\Models\Video;
use App\Models\YoutubeChannel;
use App\Models\YoutubeSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaVideoAndPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
        $this->withHeader('Origin', 'http://localhost:3000');
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('admin');
        $u->mfaMethod()->create(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'confirmed_at' => now()]);
        $this->actingAs($u)->withSession(['mfa_verified_at' => now()->timestamp]);

        return $u;
    }

    public function test_editor_can_manage_channel_controls_and_read_history_without_general_settings_permission(): void
    {
        $this->getJson('/api/v1/console/youtube/channels')->assertUnauthorized();
        Queue::fake();
        $editor = $this->admin();
        $editor->syncRoles(['editor_in_chief']);
        $this->getJson('/api/v1/console/settings')->assertForbidden();
        $body = ['provider_id' => 'UC'.str_repeat('c', 22), 'active' => false, 'auto_publish' => true, 'edition' => 'Australia'];
        $channel = $this->putJson('/api/v1/console/youtube/channels', $body)->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.auto_publish', true)->json('data.id');
        $this->getJson('/api/v1/console/youtube/channels')->assertOk()->assertJsonPath('data.0.edition', 'Australia');
        config(['builder.youtube_key' => 'local-dummy-key']);
        $this->postJson('/api/v1/console/youtube/channels/'.$channel.'/sync')->assertUnprocessable();
        $body['active'] = true;
        $this->putJson('/api/v1/console/youtube/channels', $body)->assertOk()->assertJsonPath('data.id', $channel);
        $this->assertDatabaseCount('youtube_channels', 1);
        $this->postJson('/api/v1/console/youtube/channels/'.$channel.'/sync')->assertAccepted()->assertJsonPath('data.queued', true);
        Queue::assertPushed(SyncYoutubeChannel::class);
        YoutubeSyncRun::create(['youtube_channel_id' => $channel, 'status' => 'failed', 'fetched' => 0, 'requests' => 1, 'error_code' => 'quota_exceeded', 'started_at' => now(), 'finished_at' => now()]);
        $this->getJson('/api/v1/console/youtube/history')->assertOk()->assertJsonPath('data.0.error_code', 'quota_exceeded');
        $reader = User::factory()->create();
        $reader->assignRole('registered_user');
        $this->actingAs($reader)->getJson('/api/v1/console/youtube/channels')->assertForbidden();
    }

    private function youtube(array $items): YoutubeChannel
    {
        config(['builder.youtube_key' => 'test-private-key']);
        $channel = YoutubeChannel::create(['provider_id' => 'UC'.str_repeat('a', 22), 'active' => true]);
        Http::swap(new Factory);
        Http::fake(['www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => $channel->provider_id, 'snippet' => ['title' => 'Official channel'], 'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UPLOADS']]]]]), 'www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['items' => array_map(fn ($v) => ['contentDetails' => ['videoId' => $v['id']]], $items)]), 'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => $items])]);

        return $channel;
    }

    private function video(): array
    {
        return ['id' => 'Abcdef12345', 'snippet' => ['title' => 'A real provider title', 'description' => 'Provider description', 'channelId' => 'UC'.str_repeat('a', 22), 'publishedAt' => '2026-10-10T10:00:00Z', 'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/vi/Abcdef12345/hqdefault.jpg']]], 'status' => ['privacyStatus' => 'public', 'uploadStatus' => 'processed', 'embeddable' => true], 'contentDetails' => ['duration' => 'PT12M30S']];
    }

    public function test_youtube_import_is_idempotent_reviewed_and_preserves_editorial_override(): void
    {
        $channel = $this->youtube([$this->video()]);
        $sync = app(YoutubeSync::class);
        $this->assertSame('succeeded', $sync->sync($channel)->status);
        $v = Video::firstOrFail();
        $this->assertSame(750, $v->duration_seconds);
        $this->assertSame('imported', $v->status);
        $this->getJson('/api/v1/videos')->assertJsonCount(0, 'data');
        $v->update(['title' => 'Editorial title', 'format' => 'podcast']);
        $sync->sync($channel);
        $this->assertDatabaseCount('videos', 1);
        $this->assertSame('Editorial title', $v->refresh()->title);
        $this->assertSame('podcast', $v->format);
    }

    public function test_youtube_auto_publication_and_private_reconciliation(): void
    {
        $channel = $this->youtube([$this->video()]);
        $channel->update(['auto_publish' => true]);
        $sync = app(YoutubeSync::class);
        $sync->sync($channel);
        $this->getJson('/api/v1/videos')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.embedUrl', 'https://www.youtube-nocookie.com/embed/Abcdef12345');
        Http::swap(new Factory);
        Http::fake(['www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => $channel->provider_id, 'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UPLOADS']]]]]), 'www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['items' => []]), 'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => []])]);
        $sync->sync($channel);
        $this->assertFalse(Video::firstOrFail()->available);
        $this->getJson('/api/v1/videos')->assertJsonCount(0, 'data');
    }

    public function test_foreign_channel_and_quota_errors_never_publish_or_disclose_key(): void
    {
        $v = $this->video();
        $v['snippet']['channelId'] = 'UC'.str_repeat('b', 22);
        $channel = $this->youtube([$v]);
        app(YoutubeSync::class)->sync($channel);
        $this->assertDatabaseCount('videos', 0);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['error' => ['errors' => [['reason' => 'quotaExceeded']]]], 403)]);
        $run = app(YoutubeSync::class)->sync($channel);
        $this->assertSame('failed', $run->status);
        $this->assertSame('quota_exceeded', $run->error_code);
        $this->assertStringNotContainsString('test-private-key', json_encode($run->toArray()));
    }

    public function test_scheduled_publication_is_due_only_and_repeatable(): void
    {
        $u = User::factory()->create();
        $a = Article::forceCreate(['title' => 'Due scheduled story', 'slug' => 'scheduled-story', 'summary' => 'Scheduled summary', 'body' => '<p>Scheduled body</p>', 'author_id' => $u->id, 'status' => 'scheduled', 'scheduled_for' => now()->subMinute()]);
        $future = Article::forceCreate(['title' => 'Future story', 'slug' => 'future-story', 'summary' => 'Future summary', 'body' => '<p>Future body</p>', 'author_id' => $u->id, 'status' => 'scheduled', 'scheduled_for' => now()->addDay()]);
        $this->getJson('/api/v1/articles/'.$a->slug)->assertNotFound();
        $workflow = app(Workflow::class);
        $this->assertSame(1, $workflow->publishDue());
        $this->assertSame(0, $workflow->publishDue());
        $this->getJson('/api/v1/articles/'.$a->slug)->assertOk();
        $this->getJson('/api/v1/articles/'.$future->slug)->assertNotFound();
    }

    public function test_image_upload_is_reencoded_and_alt_text_required(): void
    {
        $this->admin();
        Storage::fake('public');
        $this->postJson('/api/v1/console/media', ['file' => UploadedFile::fake()->image('portrait.jpg')])->assertUnprocessable();
        $data = $this->postJson('/api/v1/console/media', ['file' => UploadedFile::fake()->image('portrait.jpg'), 'alt' => 'Builder portrait'])->assertCreated()->json('data');
        $this->assertSame('image/webp', $data['mime']);
        $this->assertStringEndsWith('.webp', $data['path']);
        Storage::disk('public')->assertExists($data['path']);
        $this->postJson('/api/v1/console/media', ['file' => UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;'), 'alt' => 'Not an image'])->assertUnprocessable();
    }

    public function test_in_use_media_cannot_be_deleted(): void
    {
        $u = $this->admin();
        Storage::fake('public');
        $media = Media::create(['path' => 'images/used.webp', 'mime' => 'image/webp', 'size' => 100, 'alt' => 'A meaningful photo']);
        Article::forceCreate(['title' => 'Uses image', 'slug' => 'uses-image', 'author_id' => $u->id, 'media_id' => $media->id]);
        $this->deleteJson('/api/v1/console/media/'.$media->id)->assertStatus(409);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_homepage_settings_are_persisted_and_untrusted_payload_is_rejected(): void
    {
        $this->admin();
        $this->putJson('/api/v1/console/homepage', ['sections' => [['key' => 'latest', 'visible' => false], ['key' => 'lead', 'visible' => true]]])->assertOk();
        $this->assertDatabaseHas('homepage_sections', ['key' => 'latest', 'visible' => false, 'position' => 0]);
        $this->putJson('/api/v1/console/homepage', ['sections' => [['key' => 'unknown', 'visible' => true]]])->assertUnprocessable();
        $this->putJson('/api/v1/console/settings', ['siteName' => 'The Builder', 'contactEmail' => 'editor@example.com'])->assertOk();
        $this->getJson('/api/v1/settings/public')->assertJsonPath('data.siteName', 'The Builder');
    }
}
