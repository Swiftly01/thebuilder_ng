<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ApiContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Request-ID');
        if (! $id || ! preg_match('/^[a-zA-Z0-9_-]{8,80}$/', $id)) {
            $id = (string) Str::ulid();
        }$request->attributes->set('request_id', $id);
        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        if ($request->user() || ! $request->isMethod('GET')) {
            $response->headers->set('Cache-Control', 'no-store');
        }if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

return $response;
    }
}
