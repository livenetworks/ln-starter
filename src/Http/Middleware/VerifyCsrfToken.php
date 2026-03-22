<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as BaseValidateCsrfToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Extends Laravel's CSRF middleware with two additional skip conditions:
 *
 * 1. Authenticated users — via session OR bearer token (cookie/header)
 * 2. Routes with 'disable-csrf' middleware assigned
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
        // Skip CSRF for authenticated users (session auth)
        if (auth()->check()) {
            return true;
        }

        // Skip CSRF when a valid bearer token is present (cookie.auth or header).
        // This runs before Sanctum's auth middleware, so we check the token directly.
        $bearer = $request->bearerToken();
        if ($bearer && PersonalAccessToken::findToken($bearer)) {
            return true;
        }

        // Check if route has 'disable-csrf' middleware assigned
        $route = $request->route();

        if ($route) {
            $middleware = $route->gatherMiddleware();

            if (in_array('disable-csrf', $middleware)) {
                return true;
            }
        }

        return parent::inExceptArray($request);
    }
}
