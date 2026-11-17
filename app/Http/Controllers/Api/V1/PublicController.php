<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Administration\SiteMedia;
use App\Domain\Discovery\Queries;
use App\Http\Controllers\Controller;
use App\Http\Requests\DiscoveryRequest;
use App\Http\Resources\ArticleResource;
use App\Http\Resources\DirectoryResource;
use App\Http\Resources\OpportunityResource;
use App\Http\Resources\VideoResource;
use App\Models\Article;
use App\Models\Category;
use App\Models\EditorialEvent;
use App\Models\Tag;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class PublicController extends Controller
{
    public function __construct(private readonly Queries $queries) {}

    public function articles(DiscoveryRequest $r): JsonResource
    {
        return ArticleResource::collection($this->queries->articles($r->validated())->when($r->input('sort') === 'most_read', fn ($q) => $q->orderByDesc('view_count'))->latest('published_at')->paginate((int) $r->input('per_page', 12)));
    }

    public function article(string $slug): Response|JsonResource
    {
        $a = Article::published()->where('slug', $slug)->with(['author', 'category', 'media', 'tags'])->first();
        if (! $a) {
            $path = '/stories/'.$slug;
            $visited = [];
            for ($hop = 0; $hop < 10; $hop++) {
                if (in_array($path, $visited, true)) {
                    break;
                }
                $visited[] = $path;
                $to = DB::table('redirects')->where('from_path', $path)->value('to_path');
                if (! is_string($to) || ! preg_match('~^/stories/([\p{L}\p{N}\p{M}_-]{1,240})$~uD', $to, $match)) {
                    break;
                }
                if (Article::published()->where('slug', $match[1])->exists()) {
                    return response()->json(['data' => ['redirect' => $to]], 301);
                }
                $path = $to;
            }
            abort(404);
        }

        return new ArticleResource($a);
    }

    public function builders(DiscoveryRequest $r): JsonResource
    {
        return DirectoryResource::collection($this->queries->directory('builders', $r->validated())->latest()->paginate((int) $r->input('per_page', 20)));
    }

    public function builder(string $slug): JsonResource
    {
        return new DirectoryResource($this->queries->directory('builders', [])->where('slug', $slug)->firstOrFail());
    }

    public function startups(DiscoveryRequest $r): JsonResource
    {
        return DirectoryResource::collection($this->queries->directory('startups', $r->validated())->latest()->paginate((int) $r->input('per_page', 20)));
    }

    public function startup(string $slug): JsonResource
    {
        return new DirectoryResource($this->queries->directory('startups', [])->where('slug', $slug)->firstOrFail());
    }

    public function opportunities(DiscoveryRequest $r): JsonResource
    {
        return OpportunityResource::collection($this->queries->opportunities($r->validated())->orderByRaw('deadline IS NULL')->orderBy('deadline')->paginate((int) $r->input('per_page', 20)));
    }

    public function opportunity(string $slug): JsonResource
    {
        return new OpportunityResource($this->queries->opportunities([])->where('slug', $slug)->firstOrFail());
    }

    public function videos(DiscoveryRequest $r): JsonResource
    {
        return VideoResource::collection($this->videoQuery($r)->latest('provider_published_at')->paginate((int) $r->input('per_page', 12)));
    }

    public function video(Request $r, string $id): JsonResource
    {
        return new VideoResource($this->videoQuery($r)->where('id', $id)->firstOrFail());
    }

    /** @return Builder<Video> */
    private function videoQuery(Request $r): Builder
    {
        return Video::where('status', 'published')->where('available', true)->where('embeddable', true)->when($r->input('edition'), fn ($q) => $q->whereIn('edition', [$r->input('edition'), 'both']))->when($r->input('format'), fn ($q) => $q->where('format', $r->input('format')));
    }

    public function events(DiscoveryRequest $r): Response
    {
        abort_unless(config('builder.events_enabled'), 404);

        return response()->json(['data' => EditorialEvent::where('status', 'published')->where('starts_at', '>=', now())->orderBy('starts_at')->get()->map(fn ($e) => ['id' => $e->id, 'title' => $e->title, 'description' => $e->description, 'startsAt' => $e->starts_at->toISOString(), 'location' => $e->location, 'type' => $e->type, 'url' => $e->registration_url])]);
    }

    public function categories(DiscoveryRequest $r): Response
    {
        if ($r->input('edition')) {
            $names = $r->input('edition') === 'both' ? array_unique(array_merge(...array_values(config('editorial.categories')))) : config('editorial.categories.'.$r->input('edition'));

            return response()->json(['data' => Category::whereIn('name', $names)->get()->sortBy(fn ($category) => array_search($category->name, $names))->values()]);
        }

        return response()->json(['data' => Category::orderBy('name')->get()]);
    }

    public function tags(): Response
    {
        return response()->json(['data' => Tag::orderBy('name')->get()]);
    }

    public function category(string $slug): Response
    {
        return response()->json(['data' => Category::where('slug', $slug)->firstOrFail()]);
    }

    public function search(DiscoveryRequest $r): JsonResponse
    {
        $f = $r->validated();
        if (mb_strlen(trim($f['q'] ?? '')) < 2) {
            return response()->json(['data' => ['stories' => [], 'builders' => [], 'startups' => [], 'opportunities' => [], 'videos' => []]]);
        }

        return response()->json(['data' => ['stories' => ArticleResource::collection($this->queries->articles($f)->limit(20)->get()), 'builders' => DirectoryResource::collection($this->queries->directory('builders', $f)->limit(20)->get()), 'startups' => DirectoryResource::collection($this->queries->directory('startups', $f)->limit(20)->get()), 'opportunities' => OpportunityResource::collection($this->queries->opportunities($f)->limit(20)->get()), 'videos' => VideoResource::collection($this->videoQuery($r)->where(fn ($q) => $q->where('provider_title', 'like', '%'.$f['q'].'%')->orWhere('title', 'like', '%'.$f['q'].'%'))->limit(10)->get())]]);
    }

    public function suggest(DiscoveryRequest $r): Response
    {
        $all = $this->search($r)->getData(true)['data'];
        $items = [];
        foreach (['stories', 'builders', 'startups', 'opportunities', 'videos'] as $kind) {
            foreach ($all[$kind] as $x) {
                $items[] = ['title' => $x['title'] ?? $x['name'], 'href' => '/'.$kind.'/'.($x['slug'] ?? $x['id']), 'type' => $kind];
            }
        }

        return response()->json(['data' => array_slice($items, 0, 8)]);
    }

    public function home(DiscoveryRequest $r): Response
    {
        $sections = DB::table('homepage_sections')->where('key', '!=', 'picks')->orderBy('position')->get();
        $pins = fn (string $key): array => json_decode($sections->firstWhere('key', $key)->pinned_ids ?? '[]', true);
        $articles = $this->queries->articles($r->validated())->latest('published_at')->limit(20)->get();
        $leadIds = $pins('lead');
        $lead = $leadIds ? $this->queries->articles($r->validated())->whereIn('id', $leadIds)->get()->sortBy(fn ($a) => array_search($a->id, $leadIds))->values() : $articles->take(3);
        $popularIds = DB::table('analytics_events')->select('article_id')->where('type', 'view')->where('created_at', '>=', now()->subDays(7))->whereNotNull('article_id')->groupBy('article_id')->orderByRaw('COUNT(*) DESC')->limit(6)->pluck('article_id')->all();
        $popular = $this->queries->articles($r->validated())->whereIn('id', $popularIds)->get()->sortBy(fn ($a) => array_search($a->id, $popularIds))->values();

        return response()->json(['data' => [
            'sections' => $sections->map(fn ($section) => ['key' => $section->key, 'position' => $section->position, 'visible' => (bool) $section->visible, 'pinnedIds' => json_decode($section->pinned_ids ?? '[]', true)]),
            'lead' => $lead->first() ? new ArticleResource($lead->first()) : null,
            'secondary' => ArticleResource::collection($lead->slice(1)->values()),
            'latest' => ArticleResource::collection($articles),
            'trending' => ArticleResource::collection($popular->take(5)),
            'mostRead' => ArticleResource::collection($popular),
            'builders' => DirectoryResource::collection($this->queries->directory('builders', ['diaspora' => true])->when($pins('diaspora'), fn ($q) => $q->whereIn('id', $pins('diaspora')))->limit(12)->get()),
            'startups' => DirectoryResource::collection($this->queries->directory('startups', [])->when($pins('startups'), fn ($q) => $q->whereIn('id', $pins('startups')))->limit(6)->get()),
            'opportunities' => OpportunityResource::collection($this->queries->opportunities($r->validated())->orderByRaw('deadline IS NULL')->orderBy('deadline')->limit(5)->get()),
            'videos' => VideoResource::collection($this->videoQuery($r)->orderByDesc('featured')->latest('provider_published_at')->limit(4)->get()),
        ]]);
    }

    public function settings(): Response
    {
        return response()->json(['data' => SiteMedia::resolve(DB::table('site_settings')->where('public', true)->get()->mapWithKeys(fn ($s) => [$s->key => json_decode($s->value, true)])->all())]);
    }

    public function sitemap(): Response
    {
        $root = rtrim(config('builder.frontend_url'), '/');
        $urls = [[$root, now()->toAtomString()]];
        foreach (Article::published()->select(['slug', 'updated_at'])->cursor() as $a) {
            $urls[] = [$root.'/stories/'.$a->slug, $a->updated_at->toAtomString()];
        }
        foreach (['builders', 'startups'] as $kind) {
            foreach ($this->queries->directory($kind, [])->select(['slug', 'updated_at'])->cursor() as $x) {
                $urls[] = [$root.'/'.$kind.'/'.$x->slug, $x->updated_at->toAtomString()];
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as [$url,$date]) {
            $xml .= '<url><loc>'.htmlspecialchars($url, ENT_XML1).'</loc><lastmod>'.$date.'</lastmod></url>';
        }

        return response($xml.'</urlset>')->header('Content-Type', 'application/xml');
    }
}
