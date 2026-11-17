<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class YoutubeSyncRun extends Model
{
    use HasUlids;

    protected $fillable = ['youtube_channel_id', 'status', 'fetched', 'requests', 'error_code', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public $timestamps = false;
}
