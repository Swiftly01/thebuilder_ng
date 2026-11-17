<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Administration\Audit;
use App\Domain\Editorial\ArticleDocument;
use App\Domain\Editorial\LegacyArticleDocument;
use App\Domain\Editorial\Workflow;
use App\Http\Controllers\Controller;
use App\Http\Requests\ArticleRequest;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Models\ArticleRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class EditorialController extends Controller
{
    public function __construct(private readonly Workflow $workflow) {}

    public function index(Request $r): JsonResource
    {
        Gate::authorize('viewAny', Article::class);
        $r->validate(['q' => 'nullable|string|max:200', 'status' => 'nullable|in:draft,review,changes,approved,scheduled,published,archived,rejected', 'edition' => 'nullable|in:Nigeria,Australia,both', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
        $search = trim((string) $r->input('q', ''));

        return ArticleResource::collection(Article::with(['author', 'category', 'media', 'tags'])
            ->when(! $r->user()->can('content.publish'), fn ($query) => $query->where('author_id', $r->user()->id))
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('title', 'like', '%'.$search.'%')
                ->orWhereHas('author', fn ($author) => $author->where('name', 'like', '%'.$search.'%'))))
            ->when($r->status, fn ($query) => $query->where('status', $r->status))
            ->when($r->edition, fn ($query) => $query->where('edition', $r->edition))
            ->latest()->orderByDesc('id')->paginate((int) $r->input('per_page', 20))->withQueryString());
    }

    public function show(Article $article): Response
    {
        Gate::authorize('view', $article);

        return (new ArticleResource($article->load(['author', 'category', 'media', 'tags'])))->response()->header('ETag', '"'.$article->version.'"');
    }

    public function preview(Request $r, ArticleDocument $document): Response
    {
        Gate::authorize('create', Article::class);
        $data = $r->validate(['bodyDocument' => 'required|array:type,content']);

        return response()->json(['data' => ['body' => $document->prepare($data['bodyDocument'])['html']]])->header('Cache-Control', 'private, no-store');
    }

    public function import(Request $r): Response
    {
        Gate::authorize('create', Article::class);
        $data = $r->validate(['body' => 'required|string|max:100000']);
        $document = app(LegacyArticleDocument::class)->convert($data['body']);

        return response()->json(['data' => ['document' => app(ArticleDocument::class)->hydrate($document)]])->header('Cache-Control', 'private, no-store');
    }

    public function store(ArticleRequest $r): Response
    {
        return (new ArticleResource($this->workflow->save(null, $r->validated(), $r->user(), null)))->response()->setStatusCode(201);
    }

    public function update(ArticleRequest $r, Article $article): Response
    {
        $a = $this->workflow->save($article, $r->validated(), $r->user(), $r->header('If-Match'));

        return (new ArticleResource($a))->response()->header('ETag', '"'.$a->version.'"');
    }

    public function transition(Request $r, Article $article): Response
    {
        $d = $r->validate(['status' => 'required|in:draft,review,changes,approved,scheduled,published,archived,rejected', 'note' => 'nullable|string|max:4000', 'scheduledFor' => 'nullable|date']);
        $a = $this->workflow->transition($article, $d['status'], $r->user(), $r->header('If-Match'), $d['note'] ?? null, $d['scheduledFor'] ?? null);

        return (new ArticleResource($a))->response()->header('ETag', '"'.$a->version.'"');
    }

    public function versions(Article $article): Response
    {
        Gate::authorize('view', $article);

        return response()->json(['data' => ArticleRevision::where('article_id', $article->id)->latest('version')->get()]);
    }

    public function restore(Request $r, Article $article, ArticleRevision $revision): JsonResource
    {
        Gate::authorize('update', $article);
        abort_unless($revision->article_id === $article->id, 404);
        $data = $revision->snapshot;
        unset($data['status']);

        return new ArticleResource($this->workflow->save($article, $data, $r->user(), $r->header('If-Match'), true));
    }

    public function destroy(Article $article, Audit $audit): Response
    {
        Gate::authorize('delete', $article);
        $article->delete();
        $audit->record('content.deleted', request()->user(), $article);

        return response()->noContent();
    }
}
