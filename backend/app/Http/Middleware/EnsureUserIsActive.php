<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request after authentication middleware.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->is_active === false) {
            $accessToken = $user->currentAccessToken();

            if ($accessToken !== null && method_exists($accessToken, 'delete')) {
                $accessToken->delete();
            }

            return new JsonResponse([
                'message' => 'Account disabled',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
