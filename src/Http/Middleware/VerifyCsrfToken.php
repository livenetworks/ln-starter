<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as BaseValidateCsrfToken;

/**
 * Extends Laravel's CSRF middleware with two additional skip conditions:
 *
 * 1. Authenticated users — session already proves identity
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
        // Skip CSRF for authenticated users
        if (auth()->check()) {
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
