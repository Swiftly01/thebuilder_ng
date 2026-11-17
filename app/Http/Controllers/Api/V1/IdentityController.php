<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Administration\Audit;
use App\Http\Controllers\Controller;
use App\Http\Requests\IdentityRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class IdentityController extends Controller
{
    public function __construct(private readonly Audit $audit) {}

    public function register(IdentityRequest $r): UserResource
    {
        abort_unless($r->hasSession(), 400, 'A stateful browser session is required.');
        $data = $r->validated();
        $user = DB::transaction(function () use ($data) {
            $user = User::create(array_intersect_key($data, array_flip(['name', 'email', 'password'])));
            $user->assignRole('registered_user');
            $user->profile()->create(array_intersect_key($data, array_flip(['country', 'city', 'profession'])));

            return $user;
        });
        Auth::login($user);
        $r->session()->regenerate();
        $user->sendEmailVerificationNotification();
        $this->audit->record('account.registered', $user, $user);

        return new UserResource($user);
    }

    public function login(IdentityRequest $r): UserResource
    {
        abort_unless($r->hasSession(), 400, 'A stateful browser session is required.');
        $data = $r->validated();
        $key = 'login:'.hash('sha256', $data['email'].'|'.$r->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, 'Too many login attempts. Try again later.');
        }
        $user = User::where('email', $data['email'])->first();
        $hash = $user->password ?? '$2y$12$E9SWjfejHZwxMCj81URrqevVL6qIKEyf/QlkocRvpiLSFRluSPADu';
        if (! Hash::check($data['password'], $hash) || ! $user || $user->status !== 'active') {
            RateLimiter::hit($key, 900);
            $this->audit->record('authentication.failed');
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }RateLimiter::clear($key);
        Auth::login($user);
        $r->session()->regenerate();
        $r->session()->forget('mfa_verified_at');
        $this->audit->record('authentication.login', $user, $user);

        return new UserResource($user);
    }

    public function logout(Request $r): Response
    {
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $r): UserResource
    {
        return new UserResource($r->user());
    }

    public function forgotPassword(IdentityRequest $r): Response
    {
        Password::sendResetLink($r->validated());

        return response()->noContent(202);
    }

    public function resetPassword(IdentityRequest $r): Response
    {
        $result = Password::reset($r->validated(), function (User $user, string $password) {
            DB::transaction(function () use ($user, $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $this->audit->record('authentication.password.reset', $user, $user);
            });
        });
        if ($result !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => ['This reset link is invalid or expired.']]);
        }

        return response()->noContent();
    }

    public function resend(IdentityRequest $r): Response
    {
        $user = User::where('email', $r->validated('email'))->first();
        if ($user && ! $user->hasVerifiedEmail() && $user->status === 'active') {
            $user->sendEmailVerificationNotification();
        }

        return response()->noContent(202);
    }

    public function verify(Request $r, string $id, string $hash): Response
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
        abort_if($user->hasVerifiedEmail(), 409, 'This email has already been verified.');
        $user->markEmailAsVerified();
        $this->audit->record('account.email.verified', $user, $user);

        return redirect(config('builder.frontend_url').'/signin?verified=1');
    }

    public function profile(Request $r): Response
    {
        return response()->json(['data' => ['user' => new UserResource($r->user()), 'profile' => $r->user()->profile]]);
    }

    public function updateProfile(IdentityRequest $r): Response
    {
        $data = $r->validated();
        DB::transaction(function () use ($r, $data): void {
            if (isset($data['name'])) {
                $r->user()->update(['name' => $data['name']]);
            }
            unset($data['name']);
            $r->user()->profile()->updateOrCreate([], $data);
            $this->audit->record('account.profile.updated', $r->user(), $r->user());
        });

        return $this->profile($r);
    }

    public function onboarding(IdentityRequest $r): UserResource
    {
        abort_if((bool) $r->user()->onboarding_completed_at, 409, 'Onboarding is already complete.');
        DB::transaction(function () use ($r): void {
            $r->user()->profile()->updateOrCreate([], $r->validated());
            $r->user()->forceFill(['onboarding_completed_at' => now()])->save();
        });

        return new UserResource($r->user());
    }

    public function interests(IdentityRequest $r): Response
    {
        $r->user()->profile()->updateOrCreate([], ['interests' => $r->validated('interests')]);

        return response()->json(['data' => ['interests' => $r->validated('interests')]]);
    }

    public function changePassword(IdentityRequest $r): Response
    {
        if (! Hash::check($r->validated('currentPassword'), $r->user()->password)) {
            throw ValidationException::withMessages(['currentPassword' => ['The current password is incorrect.']]);
        }
        $r->user()->forceFill(['password' => $r->validated('password'), 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $r->user()->id)->where('id', '!=', $r->session()->getId())->delete();
        $r->session()->regenerate();
        $this->audit->record('account.password.changed', $r->user(), $r->user());

        return response()->noContent();
    }

    public function deleteAccount(IdentityRequest $r): Response
    {
        if (! Hash::check($r->validated('password'), $r->user()->password)) {
            throw ValidationException::withMessages(['password' => ['The password is incorrect.']]);
        }
        abort_if($r->user()->hasRole('admin'), 409, 'Transfer administrator responsibilities before requesting deletion.');
        $user = $r->user();
        $user->forceFill(['status' => 'deactivated', 'deletion_requested_at' => now()])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->audit->record('account.deletion.requested', $user, $user);

        return $this->logout($r);
    }

    public function sessions(Request $r): Response
    {
        return response()->json(['data' => DB::table('sessions')->where('user_id', $r->user()->id)->select(['id', 'user_agent', 'last_activity'])->get()->map(fn ($s) => ['id' => $s->id, 'userAgent' => $s->user_agent, 'lastActiveAt' => date(DATE_ATOM, $s->last_activity), 'current' => $s->id === $r->session()->getId()])]);
    }

    public function revokeSession(Request $r, string $id): Response
    {
        abort_if($id === $r->session()->getId(), 422, 'Use sign out to end your current session.');
        $count = DB::table('sessions')->where('user_id', $r->user()->id)->where('id', $id)->delete();
        abort_unless($count > 0, 404);

        return response()->noContent();
    }
}
