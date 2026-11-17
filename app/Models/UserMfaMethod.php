<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $confirmed_at
 * @property list<string>|null $recovery_code_hashes
 * @property int|null $last_used_timestep
 */
class UserMfaMethod extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'secret', 'recovery_code_hashes', 'last_used_timestep', 'confirmed_at'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'recovery_code_hashes' => 'array', 'last_used_timestep' => 'integer', 'confirmed_at' => 'datetime'];
    }

    protected $hidden = ['secret', 'recovery_code_hashes'];
}
