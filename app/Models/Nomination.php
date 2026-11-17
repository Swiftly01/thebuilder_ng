<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $submitter_email
 * @property string $submitter_name
 * @property string|null $country
 * @property string|null $city
 * @property string|null $industry
 */
class Nomination extends Model
{
    use HasUlids;

    protected $fillable = ['reference', 'type', 'name', 'industry', 'country', 'city', 'website', 'nominee_email', 'nominee_phone', 'submitter_name', 'submitter_email', 'relationship', 'reason', 'links', 'consent_at'];

    protected function casts(): array
    {
        return ['consent_at' => 'datetime'];
    }
}
