<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<string>|null $interests
 * @property array<string,string>|null $privacy
 * @property array<string,string>|null $social_links
 */
class Profile extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'country', 'city', 'profession', 'organisation', 'industry', 'website', 'biography', 'social_links', 'privacy', 'interests'];

    protected function casts(): array
    {
        return ['social_links' => 'array', 'privacy' => 'array', 'interests' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
