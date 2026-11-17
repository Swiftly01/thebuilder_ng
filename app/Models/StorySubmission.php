<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StorySubmission extends Model
{
    use HasUlids;

    protected $fillable = ['reference', 'user_id', 'title', 'pitch', 'why_now', 'links'];

    protected function casts(): array
    {
        return [];
    }
}
