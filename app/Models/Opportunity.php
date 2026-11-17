<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $deadline
 */
class Opportunity extends Model
{
    use HasUlids;

    protected $fillable = ['title', 'slug', 'organisation', 'type', 'location', 'region', 'remote', 'description', 'eligibility', 'apply_url', 'deadline', 'edition', 'status'];

    protected function casts(): array
    {
        return ['remote' => 'boolean', 'deadline' => 'date'];
    }

    use SoftDeletes;
}
