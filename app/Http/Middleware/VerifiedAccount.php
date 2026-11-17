<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifiedAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->hasVerifiedEmail(), 403, 'email_unverified');

        return $next($request);
    }
}
