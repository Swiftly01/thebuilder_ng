<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<array-key, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'emailVerified' => (bool) $this->email_verified_at, 'onboardingComplete' => (bool) $this->onboarding_completed_at, 'roles' => $this->getRoleNames()->values(), 'permissions' => $this->getAllPermissions()->pluck('name')->values(), 'mfaRequired' => $this->getAllPermissions()->isNotEmpty(), 'mfaEnabled' => (bool) $this->mfaMethod?->confirmed_at];
    }
}
