<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class NewsletterSubscription extends Model
{
    use HasUlids;

    protected $fillable = ['email', 'edition', 'status', 'token_hash', 'unsubscribe_hash', 'token_expires_at', 'consent_at', 'confirmed_at'];

    protected function casts(): array
    {
        return ['token_expires_at' => 'datetime', 'consent_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    protected $hidden = ['token_hash', 'unsubscribe_hash'];
}
