<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizationFromCookie
{
    /**
     * Bridge auth_token cookie to Authorization header.
     *
     * If a Sanctum token exists in the auth_token cookie and no
     * Authorization header is set, creates the Bearer header.
     * Place BEFORE AuthenticateWithSanctum in the middleware stack.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sanctumToken = $request->cookie('auth_token');

        if ($sanctumToken && !$request->header('Authorization')) {
            $request->headers->set('Authorization', 'Bearer ' . $sanctumToken);
        }

        return $next($request);
    }
}
