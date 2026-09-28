<?php

namespace LiveNetworks\LnStarter\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Validation\ValidationException;
use LiveNetworks\LnStarter\DTOs\Message;
use LiveNetworks\LnStarter\Http\ResponseMode;
use LiveNetworks\LnStarter\Support\RecordSerializer;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthExceptionHandler
{
    public static function register(ExceptionHandler $handler): void
    {
        // 401 — unauthenticated
        $handler->renderable(function (AuthenticationException $e, $request) {
            // Data mode → plain-string message (unified data error shape).
            if (ResponseMode::isData($request)) {
                return response()->json(['message' => __('Authentication is required to access this resource.')], 401)
                                 ->header('WWW-Authenticate', 'Bearer realm="api"');
            }
            if (ResponseMode::isAjax($request)) {
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
            // Data mode → plain-string message (unified data error shape).
            if (ResponseMode::isData($request)) {
                return response()->json(['message' => __('You do not have permission to perform this action.')], 403);
            }
            if (ResponseMode::isAjax($request)) {
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
            $errors = $e->errors();

            // Data mode → Laravel-standard { message, errors } (client renders toast).
            if (ResponseMode::isData($request)) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors'  => $errors,
                ], 422);
            }

            // Ajax mode → Message DTO envelope (legacy dual-mode consumers, unchanged).
            if (ResponseMode::isAjax($request)) {
                $body = '<ul>' . collect($errors)->flatten()->map(fn ($m) => '<li>' . e($m) . '</li>')->implode('') . '</ul>';
                $message = new Message('error', __('Validation Error'), $body, ['errors' => $errors]);
                return response()->json(['message' => $message, 'content' => null], 422);
            }

            // Full page → redirect back with errors (Laravel default).
            return null;
        });

        // Domain / business errors
        $handler->renderable(function (BusinessException $e, $request) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 422;

            if (ResponseMode::isData($request)) {
                return response()->json(['message' => $e->getMessage()], $status);
            }

            if (ResponseMode::isAjax($request)) {
                $message = new Message('error', $e->title, $e->getMessage());
                return response()->json(['message' => $message, 'content' => null], $status);
            }

            return null;
        });

        // 409 — optimistic-lock conflict
        $handler->renderable(function (VersionConflictException $e, $request) {
            if (ResponseMode::isData($request)) {
                // Envelope matches the existing ln-ashlar coordinator parser
                // ({remote, field_diffs}); field_diffs reserved for server-side diffing.
                return response()->json([
                    'remote'      => RecordSerializer::toArray($e->record),
                    'field_diffs' => null,
                ], 409);
            }

            if (ResponseMode::isAjax($request)) {
                $message = new Message('error', __('Conflict'), __('This record was changed by someone else. Reload and try again.'));
                return response()->json(['message' => $message, 'content' => null], 409);
            }

            return null;
        });
    }
}
