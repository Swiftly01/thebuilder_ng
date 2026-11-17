<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Editorial\ArticleDocument;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Article */
class ArticleResource extends JsonResource
{
    /** @return array<array-key, mixed> */
    public function toArray(Request $r): array
    {
        $minutes = max(1, (int) ceil(str_word_count(strip_tags($this->body ?? '')) / 200));
        // Resolve delivery URLs at detail-read time so moving storage origins does not break documents.
        $body = $this->body_document && in_array($r->route()?->getActionMethod(), ['article', 'show'], true)
            ? app(ArticleDocument::class)->prepare($this->body_document)['html'] : $this->body;

        return ($r->is('api/v1/console/*') ? ['bodySchemaVersion' => 1, 'bodyDocument' => $this->body_document ? app(ArticleDocument::class)->hydrate($this->body_document) : null] : []) + ['id' => $this->id, 'slug' => $this->slug, 'title' => $this->title, 'type' => $this->type, 'summary' => $this->summary ?? '', 'body' => $body, 'kicker' => $this->category->name ?? 'Story', 'category' => $this->category?->name, 'categorySlug' => $this->category?->slug, 'author' => $this->author->name ?? 'The Builder Editorial', 'authorId' => $this->author_id, 'date' => $this->published_at?->toISOString(), 'readTime' => $minutes.' min read', 'imageUrl' => $this->media ? Storage::disk('public')->url($this->media->path) : null, 'imageAlt' => $this->media?->alt, 'imageCredit' => $this->media?->credit, 'edition' => $this->edition, 'industry' => $this->industry, 'country' => $this->country, 'region' => $this->region, 'isSponsored' => $this->is_sponsored, 'sponsorName' => $this->sponsor_name, 'publishedAt' => $this->published_at?->toISOString(), 'seo' => $this->seo, 'tags' => $this->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug]), ...($r->is('api/v1/console/*') ? ['categoryId' => $this->category_id, 'mediaId' => $this->media_id, 'builders' => $this->builders->pluck('id'), 'startups' => $this->startups->pluck('id'), 'status' => $this->status, 'version' => $this->version, 'updatedAt' => $this->updated_at?->toISOString(), 'scheduledFor' => $this->scheduled_for?->toISOString()] : [])];
    }
}
