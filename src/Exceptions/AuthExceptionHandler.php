<?php

namespace LiveNetworks\LnStarter\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Validation\ValidationException;
use LiveNetworks\LnStarter\DTOs\Message;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthExceptionHandler
{
    public static function register(ExceptionHandler $handler): void
    {
        // 401 — unauthenticated
        $handler->renderable(function (AuthenticationException $e, $request) {
            if ($request->wantsJson() || $request->ajax()) {
                $message = new Message('error', __('Unauthenticated'), __('Authentication is required to access this resource.'));
                return response()->json(['message' => $message, 'content' => null], 401)
                                 ->header('WWW-Authenticate', 'Bearer realm="api"');
            }
            $loginRoute = config('ln-starter.exceptions.login_route', 'login');
            return redirect()->guest(route($loginRoute));
        });

        // 403 — forbidden
        // Note: AuthorizationException is converted to HttpException(403) by Laravel's
        // Handler::prepareException() before renderViaCallbacks() is called, so we
        // must listen for HttpException with status 403, not AuthorizationException.
        $handler->renderable(function (HttpException $e, $request) {
            if ($e->getStatusCode() !== 403) {
                return null;
            }
            if ($request->wantsJson() || $request->ajax()) {
                $message = new Message('error', __('Forbidden'), __('You do not have permission to perform this action.'));
                return response()->json(['message' => $message, 'content' => null], 403);
            }

            // Show 403 view if it exists, otherwise fall back to Laravel's default
            if (view()->exists('errors.403')) {
                return response()->view('errors.403', [], 403);
            }

            return null;
        });

        // 422 — validation error
        $handler->renderable(function (ValidationException $e, $request) {
            if ($request->wantsJson() || $request->ajax()) {
                $message = new Message('error', __('Validation Error'), $e->getMessage(), ['errors' => $e->errors()]);
                return response()->json(['message' => $message, 'content' => null], 422);
            }

            return null;
        });
    }
}
