<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ReaderController extends Controller
{
    public function bookmarks(Request $r): JsonResource
    {
        $ids = DB::table('bookmarks')->where('user_id', $r->user()->id)->orderByDesc('created_at')->pluck('article_id');

        return ArticleResource::collection(Article::published()->with(['author', 'category', 'media', 'tags'])->whereIn('id', $ids)->paginate(20));
    }

    public function bookmark(Request $r, Article $article): Response
    {
        abort_unless($article->status === 'published' && $article->published_at?->isPast(), 404);

        return response()->json(['data' => ['saved' => DB::table('bookmarks')->where('user_id', $r->user()->id)->where('article_id', $article->id)->exists()]]);
    }

    public function save(Request $r, Article $article): Response
    {
        abort_unless($article->status === 'published' && $article->published_at?->isPast(), 404);
        DB::table('bookmarks')->insertOrIgnore(['user_id' => $r->user()->id, 'article_id' => $article->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->noContent();
    }

    public function remove(Request $r, Article $article): Response
    {
        DB::table('bookmarks')->where('user_id', $r->user()->id)->where('article_id', $article->id)->delete();

        return response()->noContent();
    }

    public function recent(Request $r): JsonResource
    {
        $ids = DB::table('reading_history')->where('user_id', $r->user()->id)->latest('updated_at')->limit(20)->pluck('article_id')->all();
        $items = Article::published()->with(['author', 'category', 'media', 'tags'])->whereIn('id', $ids)->get()->sortBy(fn ($a) => array_search($a->id, $ids))->values();

        return ArticleResource::collection($items);
    }

    public function view(Request $r, Article $article): Response
    {
        abort_unless($article->status === 'published' && $article->published_at?->isPast(), 404);
        DB::table('reading_history')->updateOrInsert(['user_id' => $r->user()->id, 'article_id' => $article->id], ['created_at' => now(), 'updated_at' => now()]);

        return response()->noContent();
    }

    public function feed(Request $r): JsonResource
    {
        return ArticleResource::collection(Article::published()->with(['author', 'category', 'media', 'tags'])->latest('published_at')->paginate(12));
    }
}
