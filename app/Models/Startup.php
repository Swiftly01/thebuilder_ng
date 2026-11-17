<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Startup extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'slug', 'one_liner', 'description', 'industry', 'stage', 'funding_stage', 'country', 'city', 'region', 'website', 'founded_year', 'team_size', 'media_id'];

    protected function casts(): array
    {
        return ['founded_year' => 'integer', 'team_size' => 'integer'];
    }

    use SoftDeletes;

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** @return BelongsToMany<Builder, $this> */
    public function founders(): BelongsToMany
    {
        return $this->belongsToMany(Builder::class, 'startup_founders');
    }

    /** @return BelongsToMany<Article, $this> */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }
}
