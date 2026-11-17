<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $published_at
 * @property Carbon|null $scheduled_for
 * @property array<string,mixed>|null $seo
 * @property array<string,mixed>|null $body_document
 * @property bool $is_sponsored
 * @property int $version
 */
class Article extends Model
{
    protected $attributes = ['type' => 'article', 'status' => 'draft', 'version' => 1, 'edition' => 'both', 'is_sponsored' => false];

    use HasUlids;

    protected $fillable = ['title', 'slug', 'type', 'summary', 'body', 'body_document', 'author_id', 'category_id', 'media_id', 'edition', 'industry', 'country', 'region', 'is_sponsored', 'sponsor_name', 'seo'];

    protected function casts(): array
    {
        return ['body_document' => 'array', 'is_sponsored' => 'boolean', 'seo' => 'array', 'published_at' => 'datetime', 'scheduled_for' => 'datetime', 'version' => 'integer'];
    }

    use SoftDeletes;

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** @return BelongsToMany<Builder, $this> */
    public function builders(): BelongsToMany
    {
        return $this->belongsToMany(Builder::class);
    }

    /** @return BelongsToMany<Startup, $this> */
    public function startups(): BelongsToMany
    {
        return $this->belongsToMany(Startup::class);
    }

    /** @param \Illuminate\Database\Eloquent\Builder<Article> $query */
    public function scopePublished(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}
