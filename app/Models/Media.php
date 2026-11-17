<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use HasUlids;

    protected $fillable = ['uploaded_by', 'path', 'mime', 'size', 'alt', 'credit', 'width', 'height'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    protected $table = 'media';
}
