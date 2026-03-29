<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWithSanctum
{
    /**
     * Validate bearer token from Authorization header using Sanctum.
     *
     * If valid, sets the authenticated user on the request.
     * If missing or invalid, the request proceeds unauthenticated
     * (combine with Laravel's auth middleware to enforce).
     *
     * Usage:
     *   Route::middleware('sanctum.token')            — validate if present, don't block
     *   Route::middleware('sanctum.token:required')    — return 401 if not authenticated
     */
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $authenticated = false;
        $token = $request->bearerToken();

        if ($token) {
            $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);

            if ($personalAccessToken && !$personalAccessToken->revoked) {
                $request->setUserResolver(function () use ($personalAccessToken) {
                    return $personalAccessToken->tokenable;
                });
                $authenticated = true;
            }
        }

        if ($guard === 'required' && !$authenticated) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
