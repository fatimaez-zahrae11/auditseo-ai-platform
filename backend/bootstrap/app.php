<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\LogAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            LogAccess::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureUserIsAdmin::class,
        ]);

        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $previousException = $exception->getPrevious();
            $httpStatus = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : null;
            $safeHttpMessages = [
                400 => 'Bad request.',
                401 => 'Unauthenticated.',
                403 => 'Forbidden.',
                404 => 'Resource not found.',
                405 => 'Method not allowed.',
                409 => 'Conflict.',
                413 => 'Request entity too large.',
                415 => 'Unsupported media type.',
                422 => 'Unprocessable request.',
                429 => 'Too many requests.',
            ];

            return match (true) {
                $exception instanceof AuthenticationException => response()->json([
                    'message' => 'Unauthenticated.',
                ], 401),
                $exception instanceof AuthorizationException
                    || $previousException instanceof AuthorizationException => response()->json([
                        'message' => 'Forbidden.',
                    ], 403),
                $exception instanceof ModelNotFoundException
                    || $previousException instanceof ModelNotFoundException => response()->json([
                        'message' => 'Resource not found.',
                    ], 404),
                $exception instanceof ValidationException => response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $exception->errors(),
                ], 422),
                $exception instanceof ThrottleRequestsException => response()->json([
                    'message' => 'Too many requests.',
                ], 429),
                $exception instanceof HttpExceptionInterface
                    && $httpStatus >= 400
                    && $httpStatus < 500 => response()->json([
                        'message' => $safeHttpMessages[$httpStatus] ?? 'Request could not be completed.',
                    ], $httpStatus),
                default => response()->json([
                    'message' => 'Internal server error.',
                ], 500),
            };
        });
    })->create();
