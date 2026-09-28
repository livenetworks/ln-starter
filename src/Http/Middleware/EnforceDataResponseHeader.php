<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LiveNetworks\LnStarter\Http\ResponseMode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Data-mode CSRF guard. On state-changing requests (POST/PUT/PATCH/DELETE)
 * rejects (403) any request that does NOT carry the `X-LN-Response: data`
 * header. Because cross-origin HTML forms cannot set custom headers, the
 * header itself is the CSRF proof — a forged form post arrives without it and
 * is blocked. Safe methods (GET/HEAD/OPTIONS) pass through untouched.
 *
 * Apply to the route group that serves data-mode (ln-api-connector) endpoints:
 *
 *   Route::middleware(['auth:sanctum', 'ln.data'])->group(...);
 *
 * Assumptions this guard relies on:
 *  - Session cookie SameSite=Lax (Laravel default) — a third-party context
 *    cannot replay the session on a custom-header request.
 *  - No permissive CORS on these routes — a custom header triggers a CORS
 *    preflight that an attacker origin cannot satisfy.
 */
class EnforceDataResponseHeader
{
	public function handle(Request $request, Closure $next): Response
	{
		if (!$request->isMethodSafe() && !ResponseMode::hasDataHeader($request)) {
			abort(403, 'Missing required X-LN-Response: data header.');
		}

		return $next($request);
	}
}
