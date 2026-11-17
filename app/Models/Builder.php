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
 * @property string|null $country
 * @property string|null $city
 * @property string|null $profession
 * @property string|null $organisation
 * @property Carbon|null $consent_at
 * @property list<string>|null $achievements
 * @property array<string,string>|null $social_links
 */
class Builder extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'slug', 'classification', 'headline', 'biography', 'industry', 'profession', 'organisation', 'country', 'city', 'region', 'website', 'media_id', 'achievements', 'social_links', 'consent_at', 'consent_method', 'feature', 'feature_from', 'feature_until', 'is_diaspora'];

    protected function casts(): array
    {
        return ['is_diaspora' => 'boolean', 'achievements' => 'array', 'social_links' => 'array', 'consent_at' => 'datetime', 'feature_from' => 'datetime', 'feature_until' => 'datetime'];
    }

    use SoftDeletes;

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** @return BelongsToMany<Article, $this> */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }

    /** @return BelongsToMany<Startup, $this> */
    public function startups(): BelongsToMany
    {
        return $this->belongsToMany(Startup::class, 'startup_founders');
    }
}
