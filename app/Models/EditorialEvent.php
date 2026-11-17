<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 */
class EditorialEvent extends Model
{
    use HasUlids;

    protected $fillable = ['title', 'description', 'starts_at', 'ends_at', 'timezone', 'location', 'type', 'organiser', 'registration_url'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
