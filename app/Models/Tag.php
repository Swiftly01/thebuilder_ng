<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'slug'];

    protected function casts(): array
    {
        return [];
    }
}
