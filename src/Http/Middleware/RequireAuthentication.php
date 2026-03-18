<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAuthentication
{
    /**
     * Enforce authentication.
     *
     * - Browser request (non-AJAX): redirect to login route.
     * - AJAX / API request:         return 401 JSON response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            return $next($request);
        }

        if ($this->expectsJson($request)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $loginRoute = config('ln-starter.exceptions.login_route', 'login');

        return redirect()->route($loginRoute);
    }

    protected function expectsJson(Request $request): bool
    {
        return $request->expectsJson()
            || $request->ajax()
            || $request->is('api/*');
    }
}
