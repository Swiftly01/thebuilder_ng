<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'slug', 'parent_id', 'description'];

    protected function casts(): array
    {
        return [];
    }
}
