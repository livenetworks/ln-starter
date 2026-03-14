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
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token) {
            $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);

            if ($personalAccessToken && !$personalAccessToken->revoked) {
                $request->setUserResolver(function () use ($personalAccessToken) {
                    return $personalAccessToken->tokenable;
                });
            }
        }

        return $next($request);
    }
}
