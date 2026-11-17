<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

final class VerifyBot
{
    public function handle(Request $r, Closure $next): Response
    {
        if (! app()->isProduction()) {
            return $next($r);
        }
        $secret = config('services.turnstile.secret');
        abort_unless($secret, 503, 'Submission verification is unavailable.');
        $token = $r->input('turnstileToken');
        abort_unless(is_string($token) && strlen($token) <= 2048, 422, 'Complete the submission verification.');
        $result = Http::asForm()->timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => $secret, 'response' => $token, 'remoteip' => $r->ip()])->throw()->json();
        $hosts = config('services.turnstile.hostnames', []);
        abort_unless(($result['success'] ?? false) && in_array($result['hostname'] ?? '', $hosts, true), 422, 'Submission verification failed.');

        return $next($r);
    }
}
