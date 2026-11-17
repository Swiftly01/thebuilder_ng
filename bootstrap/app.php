<?php

declare(strict_types=1);

use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\ApiContext;
use App\Http\Middleware\RequireMfa;
use App\Http\Middleware\SignedPublicRead;
use App\Http\Middleware\VerifiedAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $m): void {
        $m->statefulApi();
        $m->prependToPriorityList(ThrottleRequests::class, SignedPublicRead::class);
        $m->api(prepend: [ApiContext::class]);
        $m->alias(['active' => ActiveAccount::class, 'verified' => VerifiedAccount::class, 'mfa' => RequireMfa::class, 'permission' => PermissionMiddleware::class]);
    })
    ->withExceptions(function (Exceptions $e): void {
        $e->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());
        $e->render(function (Throwable $e, Request $r) {
            if (! $r->is('api/*')) {
                return null;
            }$status = match (true) {
                $e instanceof ValidationException => 422,$e instanceof AuthenticationException => 401,$e instanceof AuthorizationException => 403,$e instanceof ModelNotFoundException => 404,$e instanceof HttpExceptionInterface => $e->getStatusCode(),default => 500
            };
            $detail = $status >= 500 ? 'An unexpected server error occurred.' : ($e->getMessage() ?: 'Request failed.');
            $body = ['type' => 'about:blank', 'title' => Response::$statusTexts[$status] ?? 'Error', 'status' => $status, 'detail' => $detail, 'message' => $detail, 'requestId' => $r->attributes->get('request_id')];
            if ($e instanceof ValidationException) {
                $body['errors'] = $e->errors();
            }

            return response()->json($body, $status, array_merge($e instanceof HttpExceptionInterface ? $e->getHeaders() : [], ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store', 'X-Request-ID' => (string) $r->attributes->get('request_id')]));
        });
    })->create();
