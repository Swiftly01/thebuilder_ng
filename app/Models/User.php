<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as VerifiesEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $onboarding_completed_at
 * @property Carbon|null $deletion_requested_at
 */
class User extends Authenticatable implements VerifiesEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles, HasUlids, MustVerifyEmail, Notifiable;

    protected $attributes = ['status' => 'active'];

    protected string $guard_name = 'web';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }

    protected function casts(): array
    {
        return ['password' => 'hashed', 'email_verified_at' => 'datetime', 'onboarding_completed_at' => 'datetime', 'deletion_requested_at' => 'datetime'];
    }

    /** @return HasOne<Profile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /** @return HasOne<UserMfaMethod, $this> */
    public function mfaMethod(): HasOne
    {
        return $this->hasOne(UserMfaMethod::class);
    }
}
