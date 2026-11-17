<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class Idempotency
{
    public function handle(Request $r, Closure $next): Response
    {
        $key = $r->header('Idempotency-Key');
        abort_unless($key && preg_match('/^[a-zA-Z0-9_-]{8,120}$/', $key), 400, 'A valid Idempotency-Key is required.');
        $scope = hash_hmac('sha256', (string) ($r->user()->id ?? $r->ip()), (string) config('app.key'));
        $fingerprint = hash('sha256', $r->method().'|'.$r->path().'|'.json_encode($r->except('turnstileToken')));

        return DB::transaction(function () use ($r, $next, $key, $scope, $fingerprint) {
            DB::table('idempotency_requests')->insertOrIgnore(['scope' => $scope, 'key' => $key, 'fingerprint' => $fingerprint, 'created_at' => now()]);
            $row = DB::table('idempotency_requests')->where('scope', $scope)->where('key', $key)->lockForUpdate()->first();
            abort_unless(hash_equals($row->fingerprint, $fingerprint), 409, 'This idempotency key was used for a different request.');
            if ($row->status) {
                return response()->json(json_decode($row->response, true), $row->status)->header('Idempotency-Replayed', 'true');
            }
            $response = $next($r);
            if ($response->isSuccessful()) {
                DB::table('idempotency_requests')->where('id', $row->id)->update(['status' => $response->getStatusCode(), 'response' => $response->getContent() ?: 'null']);
            } else {
                DB::table('idempotency_requests')->where('id', $row->id)->delete();
            }

            return $response;
        });
    }
}
