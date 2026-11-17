<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Administration\Audit;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class MetricsController extends Controller
{
    public function track(Request $r): Response
    {
        $d = $r->validate(['articleId' => 'required|exists:articles,id', 'type' => 'required|in:view,share', 'sessionId' => 'required|string|min:16|max:100', 'readSeconds' => 'sometimes|integer|min:0|max:7200', 'consent' => 'required|accepted']);
        $a = Article::published()->findOrFail($d['articleId']);
        $hash = hash_hmac('sha256', $d['sessionId'], (string) config('app.key'));
        DB::transaction(function () use ($a, $d, $hash): void {
            $inserted = DB::table('analytics_events')->insertOrIgnore(['id' => (string) Str::ulid(), 'article_id' => $a->id, 'session_hash' => $hash, 'type' => $d['type'], 'read_seconds' => $d['readSeconds'] ?? 0, 'created_at' => now(), 'bucket_at' => now()->startOfHour()]);
            if ($inserted && $d['type'] === 'view') {
                $a->increment('view_count');
            }
        });

        return response()->noContent();
    }

    public function analytics(): Response
    {
        return response()->json(['data' => ['views' => DB::table('analytics_events')->where('type', 'view')->where('created_at', '>=', now()->subDays(30))->count(), 'shares' => DB::table('analytics_events')->where('type', 'share')->where('created_at', '>=', now()->subDays(30))->count(), 'articles' => Article::published()->orderByDesc('view_count')->limit(20)->get(['id', 'title', 'view_count'])]]);
    }

    public function campaigns(): Response
    {
        return response()->json(Campaign::latest()->paginate(20));
    }

    public function saveCampaign(Request $r, Audit $audit): Response
    {
        $d = $r->validate(['id' => 'nullable|exists:campaigns,id', 'name' => 'required|string|max:180', 'advertiser' => 'required|string|max:180', 'placement' => 'required|in:home_top,article_inline,category_side,directory_top,newsletter', 'media_id' => 'nullable|exists:media,id', 'target_url' => 'required|url:http,https|max:255', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'status' => 'required|in:draft,active,paused,ended']);
        $item = isset($d['id']) ? Campaign::findOrFail($d['id']) : new Campaign;
        unset($d['id']);
        $item->fill($d)->save();
        $audit->record('campaign.saved', $r->user(), $item);

        return response()->json(['data' => $item]);
    }

    public function ad(string $placement): Response
    {
        $item = Campaign::where('placement', $placement)->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now())->first();

        return response()->json(['data' => $item ? ['id' => $item->id, 'label' => 'Sponsored', 'advertiser' => $item->advertiser, 'targetUrl' => $item->target_url, 'mediaId' => $item->media_id, 'mediaUrl' => $item->media_id ? Storage::disk('public')->url(Media::find($item->media_id)->path) : null] : null]);
    }

    public function adEvent(Request $r, Campaign $campaign): Response
    {
        $d = $r->validate(['type' => 'required|in:impression,click', 'consent' => 'required|accepted']);
        abort_unless($campaign->status === 'active' && $campaign->starts_at->isPast() && $campaign->ends_at->isFuture(), 404);
        $campaign->increment($d['type'] === 'click' ? 'clicks' : 'impressions');

        return response()->noContent();
    }
}
