<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Marker middleware for bearer-only CSRF exemptions.
 *
 * This middleware itself does nothing — it marks the route.
 * The actual CSRF skip logic is in VerifyCsrfToken which
 * checks if this middleware is assigned to the route.
 *
 * Usage:
 *   Route::middleware(['auth:sanctum', 'disable-csrf:bearer'])->group(function () {
 *       // Authorization-header bearer-token routes
 *   });
 *
 * VerifyCsrfToken only honors this marker when the request contains an
 * Authorization bearer token. Session-only and cookie-only requests remain
 * CSRF protected even though Sanctum can authenticate them.
 */
class DisableCsrf
{
    public function handle(Request $request, Closure $next, ?string $mode = null)
    {
        return $next($request);
    }
}
