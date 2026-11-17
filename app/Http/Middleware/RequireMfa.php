<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->mfaMethod?->confirmed_at) {
            abort(403, 'mfa_setup_required');
        }
        $at = $request->hasSession() ? $request->session()->get('mfa_verified_at') : null;
        abort_unless(is_numeric($at) && now()->timestamp - (int) $at < config('builder.mfa_idle_minutes') * 60, 403, 'mfa_required');
        $request->session()->put('mfa_verified_at', now()->timestamp);

        return $next($request);
    }
}
