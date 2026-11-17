<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string,mixed> $snapshot
 */
class ArticleRevision extends Model
{
    use HasUlids;

    protected $fillable = ['article_id', 'actor_id', 'version', 'snapshot', 'note'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer'];
    }

    public $timestamps = false;
}
