<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        // Delegate token parsing and validation to Sanctum itself. This keeps
        // expiry, provider checks, currentAccessToken(), last_used_at, and
        // Sanctum authentication events consistent with auth:sanctum.
        $user = Auth::guard('sanctum')->user();

        if ($user) {
            Auth::shouldUse('sanctum');
            $request->setUserResolver(static fn () => $user);
        }

        if ($mode === 'required' && !$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401)
                ->header('WWW-Authenticate', 'Bearer realm="api"');
        }

        return $next($request);
    }
}
