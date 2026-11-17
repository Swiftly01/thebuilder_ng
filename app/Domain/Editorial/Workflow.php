<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Domain\Administration\Audit;
use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class Workflow
{
    private const MOVES = ['draft' => ['review'], 'review' => ['approved', 'changes', 'rejected'], 'changes' => ['draft', 'review'], 'approved' => ['scheduled', 'published'], 'scheduled' => ['approved', 'published'], 'published' => ['approved', 'archived'], 'archived' => ['draft'], 'rejected' => ['draft']];

    public function __construct(private readonly Audit $audit) {}

    /** @param array<array-key, mixed> $data */
    public function save(?Article $article, array $data, User $actor, ?string $expected, bool $restore = false): Article
    {
        return DB::transaction(function () use ($article, $data, $actor, $expected, $restore): Article {
            if ($article) {
                $article = Article::whereKey($article->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('update', $article);
                $this->checkVersion($article, $expected);
            } else {
                Gate::forUser($actor)->authorize('create', Article::class);
                $article = new Article;
                $article->author_id = $actor->id;
            }
            $oldSlug = $article->slug;
            if ($restore && array_key_exists('type', $data) && $data['type'] === null) {
                $data['type'] = $article->type ?: 'article';
            }
            $wasPublished = (bool) $article->published_at;
            $mapping = ['authorId' => 'author_id', 'categoryId' => 'category_id', 'mediaId' => 'media_id', 'isSponsored' => 'is_sponsored', 'sponsorName' => 'sponsor_name'];
            foreach ($mapping as $from => $to) {
                if (array_key_exists($from, $data)) {
                    $data[$to] = $data[$from];
                    unset($data[$from]);
                }
            }
            if ($actor->cannot('content.publish')) {
                unset($data['author_id']);
            }
            $relations = [];
            foreach (['tags', 'builders', 'startups'] as $key) {
                if (array_key_exists($key, $data)) {
                    $relations[$key] = $data[$key];
                    unset($data[$key]);
                }
            }
            $document = $data['bodyDocument'] ?? $data['body_document'] ?? null;
            unset($data['bodyDocument'], $data['body_document']);
            if ($document !== null) {
                if (array_key_exists('body', $data) && ! $restore) {
                    throw ValidationException::withMessages(['bodyDocument' => ['Submit the document without a separate HTML body.']]);
                }
                $prepared = app(ArticleDocument::class)->prepare($document, true);
                $data['body_document'] = $prepared['document'];
                $data['body'] = $prepared['html'];
            } elseif (array_key_exists('body', $data)) {
                if ($article->body_document && ! $restore) {
                    throw ValidationException::withMessages(['bodyDocument' => ['This article uses the rich editor. Reload it before editing.']]);
                }
                $data['body_document'] = null;
                $data['body'] = app(RichText::class)->sanitize((string) $data['body']);
            }
            if (! isset($data['slug']) && ! $article->exists) {
                $data['slug'] = Str::slug($data['title']).'-'.Str::lower(Str::random(6));
            }
            if (isset($data['slug']) && Article::where('slug', $data['slug'])->when($article->exists, fn ($q) => $q->where('id', '!=', $article->id))->exists()) {
                throw ValidationException::withMessages(['slug' => ['This slug is already used.']]);
            }
            if ($wasPublished && array_key_exists('is_sponsored', $data) && ! $data['is_sponsored'] && $article->is_sponsored) {
                throw ValidationException::withMessages(['isSponsored' => ['A published sponsorship label cannot be removed.']]);
            }
            $article->fill($data);
            $article->version = $article->exists ? $article->version + 1 : 1;
            $article->save();
            $this->mediaUses($article);
            foreach ($relations as $key => $ids) {
                $article->$key()->sync($ids);
            }
            if ($wasPublished && $oldSlug !== $article->slug) {
                DB::table('redirects')->where('to_path', '/stories/'.$oldSlug)->update(['to_path' => '/stories/'.$article->slug, 'updated_at' => now()]);
                DB::table('redirects')->updateOrInsert(['from_path' => '/stories/'.$oldSlug], ['to_path' => '/stories/'.$article->slug, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->snapshot($article, $actor);
            $this->audit->record('content.saved', $actor, $article, ['version' => $article->version]);

            return $article->load(['author', 'category', 'media', 'tags']);
        });
    }

    public function transition(Article $article, string $status, User $actor, ?string $expected, ?string $note = null, ?string $scheduled = null): Article
    {
        return DB::transaction(function () use ($article, $status, $actor, $expected, $note, $scheduled): Article {
            $article = Article::whereKey($article->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('transition', [$article, $status]);
            $this->checkVersion($article, $expected);
            if (! in_array($status, self::MOVES[$article->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => ['This publishing transition is not allowed.']]);
            }
            if (in_array($status, ['changes', 'rejected'], true) && blank($note)) {
                throw ValidationException::withMessages(['note' => ['A review note is required.']]);
            }
            if (in_array($status, ['scheduled', 'published'], true) && (blank($article->body) || blank($article->summary))) {
                throw ValidationException::withMessages(['body' => ['Complete the body and summary before publishing.']]);
            }
            if ($status === 'scheduled' && (! $scheduled || Carbon::parse($scheduled)->isPast())) {
                throw ValidationException::withMessages(['scheduledFor' => ['Choose a future publishing time.']]);
            }
            if (in_array($status, ['scheduled', 'published'], true) && $article->body_document) {
                $article->body = app(ArticleDocument::class)->prepare($article->body_document, true)['html'];
            }
            $from = $article->status;
            $article->status = $status;
            $article->scheduled_for = $status === 'scheduled' ? Carbon::parse($scheduled) : null;
            if ($status === 'published') {
                $article->published_at = now();
            }
            $article->version++;
            $article->save();
            $this->snapshot($article, $actor, $note);
            $this->audit->record('content.transition', $actor, $article, ['from' => $from, 'to' => $status]);

            return $article->load(['author', 'category', 'media', 'tags']);
        });
    }

    public function publishDue(): int
    {
        $count = 0;
        Article::where('status', 'scheduled')->where('scheduled_for', '<=', now())->orderBy('id')->chunkById(50, function ($items) use (&$count): void {
            foreach ($items as $item) {
                DB::transaction(function () use ($item, &$count): void {
                    $a = Article::whereKey($item->id)->lockForUpdate()->firstOrFail();
                    if ($a->status !== 'scheduled' || ! $a->scheduled_for || $a->scheduled_for->isFuture()) {
                        return;
                    }
                    $a->status = 'published';
                    if ($a->body_document) {
                        $a->body = app(ArticleDocument::class)->prepare($a->body_document, true)['html'];
                    }
                    $a->published_at = now();
                    $a->scheduled_for = null;
                    $a->version++;
                    $a->save();
                    $this->snapshot($a, null, 'Scheduled publication');
                    $this->audit->record('content.scheduled.published', null, $a);
                    $count++;
                });
            }
        });

        return $count;
    }

    private function checkVersion(Article $a, ?string $expected): void
    {
        abort_if($expected === null, 428, 'Send If-Match with the current article version.');
        abort_unless(trim($expected, '"') === (string) $a->version, 412, 'This article has changed. Reload before saving.');
    }

    private function snapshot(Article $a, ?User $u, ?string $note = null): void
    {
        $revision = ArticleRevision::create(['article_id' => $a->id, 'actor_id' => $u?->id, 'version' => $a->version, 'snapshot' => $a->only(['title', 'slug', 'type', 'summary', 'body', 'body_document', 'author_id', 'category_id', 'media_id', 'edition', 'industry', 'country', 'region', 'is_sponsored', 'sponsor_name', 'seo', 'status']) + ['tags' => $a->tags()->pluck('tags.id')->all(), 'builders' => $a->builders()->pluck('builders.id')->all(), 'startups' => $a->startups()->pluck('startups.id')->all()], 'note' => $note]);
        $this->mediaUses($a, $revision->id);
    }

    private function mediaUses(Article $article, ?string $revision = null): void
    {
        $ids = $article->body_document ? app(ArticleDocument::class)->ids($article->body_document) : [];
        if ($article->media_id) {
            $ids[] = $article->media_id;
        }
        DB::table('article_media_uses')->where('article_id', $article->id)->where('revision_id', $revision)->delete();
        foreach (array_unique($ids) as $id) {
            DB::table('article_media_uses')->insert(['article_id' => $article->id, 'revision_id' => $revision, 'media_id' => $id]);
        }
    }
}
