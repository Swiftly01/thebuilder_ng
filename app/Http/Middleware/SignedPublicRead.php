<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Separates trusted, anonymous SSR reads from the browser IP limiter. */
final class SignedPublicRead
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Builder-SSR-Signature');
        if ($signature === null) {
            return $next($request);
        }
        $timestamp = $request->header('X-Builder-SSR-Timestamp', '');
        $secret = (string) config('builder.ssr_secret');
        abort_unless($request->isMethod('GET') && ! $request->headers->has('Cookie') && ! $request->headers->has('Authorization'), 401);
        abort_unless(strlen($secret) >= 32 && preg_match('/^\d{10}$/', $timestamp) && abs(now()->timestamp - (int) $timestamp) <= 30, 401);
        $expected = hash_hmac('sha256', $timestamp."\nGET\n".$request->getRequestUri(), $secret);
        abort_unless(hash_equals($expected, $signature), 401);
        $request->attributes->set('builder_ssr', true);

        return $next($request);
    }
}
