<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class YoutubeChannel extends Model
{
    use HasUlids;

    protected $attributes = ['active' => true, 'auto_publish' => false, 'edition' => 'both'];

    protected $fillable = ['provider_id', 'title', 'uploads_playlist', 'active', 'auto_publish', 'edition', 'last_synced_at', 'reconcile_after_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'auto_publish' => 'boolean', 'last_synced_at' => 'datetime'];
    }
}
