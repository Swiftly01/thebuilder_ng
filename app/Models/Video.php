<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $provider_published_at
 */
class Video extends Model
{
    use HasUlids;

    protected $fillable = ['youtube_channel_id', 'youtube_id', 'provider_title', 'provider_description', 'title', 'description', 'thumbnail_url', 'duration_seconds', 'provider_published_at', 'available', 'embeddable', 'edition', 'format'];

    protected function casts(): array
    {
        return ['available' => 'boolean', 'embeddable' => 'boolean', 'featured' => 'boolean', 'duration_seconds' => 'integer', 'provider_published_at' => 'datetime'];
    }
}
