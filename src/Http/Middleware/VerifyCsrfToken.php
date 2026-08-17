<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as BaseValidateCsrfToken;

/**
 * Extends Laravel's CSRF middleware with an explicit route-level opt-out.
 *
 * Authentication is not a substitute for CSRF protection. Session and cookie
 * authenticated requests must still prove that the request originated from
 * the application. Only routes that explicitly carry the 'disable-csrf'
 * marker are excluded (for example, bearer-token APIs or webhooks).
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
