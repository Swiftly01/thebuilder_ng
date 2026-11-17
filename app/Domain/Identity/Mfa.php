<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Administration\Audit;
use App\Models\User;
use App\Models\UserMfaMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class Mfa
{
    public function __construct(private readonly Totp $totp, private readonly Audit $audit) {}

    /** @return array<array-key, mixed> */
    public function setup(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if((bool) $user->mfaMethod?->confirmed_at, 409, 'MFA is already configured.');
            $secret = $this->totp->generateSecret();
            $user->mfaMethod()->updateOrCreate([], ['secret' => $secret, 'last_used_timestep' => null]);

            return ['secret' => $secret, 'provisioningUri' => $this->totp->provisioningUri($secret, $user->email)];
        });
    }

    /** @return array<array-key, mixed> */
    public function verify(User $user, ?string $code, ?string $recovery, bool $confirm = false): array
    {
        return DB::transaction(function () use ($user, $code, $recovery, $confirm): array {
            $method = UserMfaMethod::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $method || ($confirm ? (bool) $method->confirmed_at : ! $method->confirmed_at)) {
                throw ValidationException::withMessages(['code' => ['Start or complete MFA setup first.']]);
            }
            if ($recovery && ! $confirm) {
                $hashes = $method->recovery_code_hashes ?? [];
                $found = false;
                foreach ($hashes as $i => $hash) {
                    if (Hash::check(Str::upper(trim($recovery)), $hash)) {
                        unset($hashes[$i]);
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    throw ValidationException::withMessages(['recoveryCode' => ['Invalid or previously used recovery code.']]);
                }
                $method->recovery_code_hashes = array_values($hashes);
            } else {
                $step = $this->totp->matchingTimestep($method->secret, (string) $code);
                if ($step === null || ($method->last_used_timestep !== null && $step <= $method->last_used_timestep)) {
                    throw ValidationException::withMessages(['code' => ['Invalid or previously used authentication code.']]);
                }
                $method->last_used_timestep = $step;
            }
            $codes = [];
            if ($confirm) {
                $codes = array_map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)), range(1, 8));
                $method->recovery_code_hashes = array_map(fn ($c) => Hash::make($c), $codes);
                $method->confirmed_at = now();
            }
            $method->save();
            $this->audit->record($confirm ? 'authentication.mfa.enabled' : 'authentication.mfa.verified', $user, $user);

            return $codes;
        });
    }
}
