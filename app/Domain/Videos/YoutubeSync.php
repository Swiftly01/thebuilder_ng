<?php

declare(strict_types=1);

namespace App\Domain\Videos;

use App\Domain\Administration\Audit;
use App\Models\Video;
use App\Models\YoutubeChannel;
use App\Models\YoutubeSyncRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class YoutubeSync
{
    private int $requests = 0;

    private float $startedAt = 0;

    public function __construct(private readonly Audit $audit) {}

    public function sync(YoutubeChannel $channel): YoutubeSyncRun
    {
        abort_unless($channel->active, 422, 'Channel import is disabled.');
        abort_unless(config('builder.youtube_key'), 503, 'YouTube API credentials are not configured.');
        $lock = Cache::lock('youtube:'.$channel->id, 240);
        abort_unless($lock->get(), 409, 'A synchronization is already running.');
        $this->requests = 0;
        $this->startedAt = microtime(true);
        $run = YoutubeSyncRun::create(['youtube_channel_id' => $channel->id, 'status' => 'running', 'started_at' => now()]);
        try {
            $source = $this->get('channels', ['part' => 'snippet,contentDetails', 'id' => $channel->provider_id]);
            $item = $source['items'][0] ?? null;
            if (! $item || ($item['id'] ?? null) !== $channel->provider_id) {
                throw new \RuntimeException('channel_not_found');
            }
            $playlist = $item['contentDetails']['relatedPlaylists']['uploads'] ?? null;
            if (! $playlist) {
                throw new \RuntimeException('uploads_playlist_missing');
            }
            $channel->update(['title' => $item['snippet']['title'] ?? $channel->title, 'uploads_playlist' => $playlist]);
            $ids = [];
            $token = null;
            for ($page = 0; $page < min(5, max(1, (int) config('builder.youtube_max_pages'))); $page++) {
                $data = $this->get('playlistItems', array_filter(['part' => 'contentDetails', 'playlistId' => $playlist, 'maxResults' => 50, 'pageToken' => $token]));
                foreach ($data['items'] ?? [] as $x) {
                    $id = $x['contentDetails']['videoId'] ?? '';
                    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
                        $ids[] = $id;
                    }
                }
                $token = $data['nextPageToken'] ?? null;
                if (! $token) {
                    break;
                }
            }
            // Reconcile every stored video in bounded ID batches, including IDs removed from the playlist.
            $storedBatch = Video::where('youtube_channel_id', $channel->id)->when($channel->reconcile_after_id, fn ($q) => $q->where('id', '>', $channel->reconcile_after_id))->orderBy('id')->limit(100)->get(['id', 'youtube_id']);
            $stored = $storedBatch->pluck('youtube_id')->all();
            $ids = array_values(array_unique(array_merge($ids, $stored)));

            $fetched = 0;
            foreach (array_chunk($ids, 50) as $batch) {
                $result = $this->get('videos', ['part' => 'snippet,contentDetails,status', 'id' => implode(',', $batch)]);
                $found = [];
                DB::transaction(function () use ($result, $batch, $channel, &$found, &$fetched): void {
                    foreach ($result['items'] ?? [] as $v) {
                        $id = $v['id'] ?? '';
                        if (! in_array($id, $batch, true) || ($v['snippet']['channelId'] ?? null) !== $channel->provider_id) {
                            continue;
                        }
                        $found[] = $id;
                        $available = ($v['status']['privacyStatus'] ?? 'private') === 'public' && ($v['status']['uploadStatus'] ?? '') === 'processed';
                        $embeddable = (bool) ($v['status']['embeddable'] ?? false);
                        try {
                            $duration = new \DateInterval($v['contentDetails']['duration'] ?? 'PT0S');
                            $seconds = $duration->d * 86400 + $duration->h * 3600 + $duration->i * 60 + $duration->s;
                        } catch (\Exception) {
                            $seconds = 0;
                        }
                        $video = Video::firstOrNew(['youtube_id' => $id]);
                        if ($video->exists && $video->youtube_channel_id !== $channel->id) {
                            throw new \RuntimeException('channel_mismatch');
                        }
                        $new = ! $video->exists;
                        $video->fill(['youtube_channel_id' => $channel->id, 'provider_title' => mb_substr($v['snippet']['title'] ?? '', 0, 255), 'provider_description' => $v['snippet']['description'] ?? '', 'thumbnail_url' => $v['snippet']['thumbnails']['high']['url'] ?? $v['snippet']['thumbnails']['default']['url'] ?? null, 'provider_published_at' => $v['snippet']['publishedAt'] ?? null, 'duration_seconds' => $seconds, 'available' => $available, 'embeddable' => $embeddable, 'edition' => $channel->edition]);
                        if ($new) {
                            $video->status = $channel->auto_publish && $available && $embeddable && $seconds > 0 ? 'published' : 'imported';
                        }
                        $video->save();
                        $fetched++;
                    }Video::where('youtube_channel_id', $channel->id)->whereIn('youtube_id', array_diff($batch, $found))->update(['available' => false]);
                });
            }
            $channel->update(['last_synced_at' => now(), 'reconcile_after_id' => $storedBatch->count() === 100 ? $storedBatch->last()->id : null]);
            $run->update(['status' => 'succeeded', 'fetched' => $fetched, 'requests' => $this->requests, 'finished_at' => now()]);
            $this->audit->record('youtube.synced', null, $channel, ['fetched' => $fetched, 'requests' => $this->requests]);
        } catch (\Throwable $e) {
            $code = $e instanceof \RuntimeException ? $e->getMessage() : 'sync_failed';
            $code = preg_match('/^[a-z_]{3,80}$/', $code) ? $code : 'sync_failed';
            $run->update(['status' => 'failed', 'error_code' => $code, 'requests' => $this->requests, 'finished_at' => now()]);
            $this->audit->record('youtube.sync_failed', null, $channel, ['error' => $code]);
        } finally {
            $lock->release();
        }

        return $run->refresh();
    }

    /** @param array<array-key, mixed> $query
     * @return array<array-key, mixed> */
    private function get(string $endpoint, array $query): array
    {
        if (microtime(true) - $this->startedAt > 110) {
            throw new \RuntimeException('sync_budget_exceeded');
        }
        $this->requests++;
        try {
            $response = Http::acceptJson()->timeout(12)->connectTimeout(5)->retry(2, fn ($attempt) => min(2000, 200 * $attempt + random_int(0, 100)), fn ($error) => $error instanceof ConnectionException || ($error instanceof RequestException && ($error->response->status() === 429 || $error->response->serverError())), throw: false)->get('https://www.googleapis.com/youtube/v3/'.$endpoint, $query + ['key' => config('builder.youtube_key')]);
            if (! $response->successful()) {
                $reason = $response->json('error.errors.0.reason');
                throw new \RuntimeException(match ($reason) {
                    'quotaExceeded','dailyLimitExceeded' => 'quota_exceeded','keyInvalid' => 'invalid_credential',default => 'provider_request_failed'
                });
            }

            return $response->json();
        } catch (ConnectionException) {
            throw new \RuntimeException('provider_unreachable');
        }
    }
}
