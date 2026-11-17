<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Administration\Audit;
use App\Http\Controllers\Controller;
use App\Jobs\SyncYoutubeChannel;
use App\Models\Video;
use App\Models\YoutubeChannel;
use App\Models\YoutubeSyncRun;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class YoutubeController extends Controller
{
    public function channels(): Response
    {
        return response()->json(['data' => YoutubeChannel::all()]);
    }

    public function saveChannel(Request $r, Audit $audit): Response
    {
        $d = $r->validate(['provider_id' => 'required|string|regex:/^UC[A-Za-z0-9_-]{22}$/', 'active' => 'sometimes|boolean', 'auto_publish' => 'sometimes|boolean', 'edition' => 'sometimes|in:Nigeria,Australia,both']);
        $channel = YoutubeChannel::updateOrCreate(['provider_id' => $d['provider_id']], $d);
        $audit->record('youtube.channel.configured', $r->user(), $channel, ['autoPublish' => $channel->auto_publish]);

        return response()->json(['data' => $channel]);
    }

    public function sync(Request $r, YoutubeChannel $channel, Audit $audit): Response
    {
        abort_unless(config('builder.youtube_key'), 503, 'YouTube API credentials are not configured.');
        abort_unless($channel->active, 422, 'Channel import is disabled.');
        SyncYoutubeChannel::dispatch($channel->id);
        $audit->record('youtube.sync.requested', $r->user(), $channel);

        return response()->json(['data' => ['queued' => true]], 202);
    }

    public function history(): Response
    {
        return response()->json(YoutubeSyncRun::latest('started_at')->paginate(25));
    }

    public function videos(): Response
    {
        return response()->json(Video::latest('provider_published_at')->paginate(25));
    }

    public function updateVideo(Request $r, Video $video, Audit $audit): Response
    {
        $d = $r->validate(['title' => 'nullable|string|max:255', 'description' => 'nullable|string|max:10000', 'format' => 'sometimes|nullable|in:interview,podcast,documentary', 'status' => 'sometimes|in:imported,published,archived', 'featured' => 'sometimes|boolean']);
        abort_if(($d['status'] ?? null) === 'published' && (! $video->available || ! $video->embeddable), 422, 'Only public embeddable videos can be published.');
        $video->fill(array_intersect_key($d, array_flip(['title', 'description', 'format'])));
        if (isset($d['status'])) {
            $video->status = $d['status'];
        }
        if (isset($d['featured'])) {
            $video->featured = $d['featured'];
        }
        $video->save();
        $audit->record('video.updated', $r->user(), $video, ['status' => $video->status]);

        return response()->json(['data' => $video]);
    }
}
