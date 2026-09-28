<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as BaseValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use LiveNetworks\LnStarter\Http\ResponseMode;

/**
 * Extends Laravel's CSRF middleware with an explicit route-level opt-out.
 *
 * Authentication is not a substitute for CSRF protection. Session and cookie
 * authenticated requests must still prove that the request originated from
 * the application. Only routes carrying the 'disable-csrf:bearer' marker and
 * an explicit Authorization bearer token, with no authenticated web session,
 * are excluded.
 *
 * Register by replacing Laravel's default in bootstrap/app.php:
 *
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->web(replace: [
 *           \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class
 *               => \LiveNetworks\LnStarter\Http\Middleware\VerifyCsrfToken::class,
 *       ]);
 *   })
 */
class VerifyCsrfToken extends BaseValidateCsrfToken
{
    protected function inExceptArray($request): bool
    {
        $route = $request->route();

        if ($route) {
            $middleware = $route->gatherMiddleware();

            if (
                in_array('disable-csrf:bearer', $middleware, true)
                && $request->bearerToken()
                && !$request->attributes->get(AuthorizationFromCookie::REQUEST_ATTRIBUTE, false)
                && !Auth::guard('web')->check()
            ) {
                return true;
            }
        }

        // Skip CSRF for data-mode requests: the X-LN-Response header itself is
        // the CSRF proof on routes guarded by the ln.data middleware (no
        // synchronizer token — an offline request queue would outlive stamped
        // tokens). The EnforceDataResponseHeader guard rejects header-less
        // state-changing requests separately.
        if (ResponseMode::hasDataHeader($request)) {
            $route = $request->route();

            if ($route && in_array('ln.data', $route->gatherMiddleware(), true)) {
                return true;
            }
        }

        return parent::inExceptArray($request);
    }
}
