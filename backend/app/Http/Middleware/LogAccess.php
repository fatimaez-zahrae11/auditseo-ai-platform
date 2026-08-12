<?php

namespace App\Http\Middleware;

use App\Models\AccessLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if ($this->shouldSkip($request)) {
                return;
            }

            $routeUri = $request->route()?->uri();

            AccessLog::create([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'ip_address' => $request->ip() === null
                    ? null
                    : Str::limit((string) $request->ip(), 45, ''),
                'method' => Str::limit($request->getMethod(), 10, ''),
                'route' => $routeUri === null
                    ? null
                    : Str::limit('/'.ltrim($routeUri, '/'), 255, ''),
                'status_code' => $response->getStatusCode(),
                'user_agent' => $request->userAgent() === null
                    ? null
                    : Str::limit($request->userAgent(), 500, ''),
            ]);
        } catch (Throwable) {
            // Access logging must never change or break the API response.
        }
    }

    private function shouldSkip(Request $request): bool
    {
        if ($request->is('api/health')) {
            return true;
        }

        return $request->is('api/health/readiness') && $request->user() === null;
    }
}
