<?php

namespace LiveNetworks\LnStarter\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use LiveNetworks\LnStarter\DTOs\Message;

class AuthExceptionHandler
{
    public static function register(ExceptionHandler $handler): void
    {
        $handler->renderable(function (AuthenticationException $e, $request) {
            if ($request->wantsJson() || $request->ajax()) {
                $message = new Message('error', __('Unauthenticated'), __('Authentication is required to access this resource.'));
                return response()->json(['message' => $message, 'content' => null], 401)
                                 ->header('WWW-Authenticate', 'Bearer realm="api"');
            }
            $loginRoute = config('ln-starter.exceptions.login_route', 'login');
            return redirect()->guest(route($loginRoute));
        });

        $handler->renderable(function (AuthorizationException $e, $request) {
            if ($request->wantsJson() || $request->ajax()) {
                $message = new Message('error', __('Forbidden'), __('You do not have permission to perform this action.'));
                return response()->json(['message' => $message, 'content' => null], 403);
            }
            if (!auth()->check()) {
                $loginRoute = config('ln-starter.exceptions.login_route', 'login');
                return redirect()->guest(route($loginRoute));
            }
            abort(403);
        });
    }
}
