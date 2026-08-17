<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Marker middleware for disabling CSRF on routes.
 *
 * This middleware itself does nothing — it marks the route.
 * The actual CSRF skip logic is in VerifyCsrfToken which
 * checks if this middleware is assigned to the route.
 *
 * Usage:
 *   Route::middleware(['auth:sanctum', 'disable-csrf'])->group(function () {
 *       // Authorization-header bearer-token routes without cookie auth
 *   });
 *
 * Do not use this marker for session or cookie-authenticated browser routes.
 */
class DisableCsrf
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}
