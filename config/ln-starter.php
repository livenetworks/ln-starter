<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App Layout
    |--------------------------------------------------------------------------
    |
    | The Blade layout used for full-page (non-AJAX) requests.
    | Your project must provide this layout.
    |
    */
    'layout' => 'layouts._app',

    /*
    |--------------------------------------------------------------------------
    | AJAX Layout
    |--------------------------------------------------------------------------
    |
    | The Blade layout used for AJAX requests. Returns JSON with rendered
    | Blade sections. The package provides a default; override if needed.
    |
    */
    'ajax_layout' => 'layouts._ajax',

    /*
    |--------------------------------------------------------------------------
    | Middleware Aliases
    |--------------------------------------------------------------------------
    |
    | Middleware aliases registered by the package. You can override these
    | in your project's kernel or bootstrap if needed.
    |
    */
    'middleware_aliases' => [
        'sanctum.token' => \LiveNetworks\LnStarter\Http\Middleware\AuthenticateWithSanctum::class,
        'cookie.auth'   => \LiveNetworks\LnStarter\Http\Middleware\AuthorizationFromCookie::class,
        'disable-csrf'  => \LiveNetworks\LnStarter\Http\Middleware\DisableCsrf::class,
        'ln.auth'       => \LiveNetworks\LnStarter\Http\Middleware\RequireAuthentication::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth Module (Passwordless / Magic Link)
    |--------------------------------------------------------------------------
    |
    | Enable the built-in passwordless authentication flow.
    | When enabled, the package registers login/logout routes,
    | loads the magic_link_tokens migration, and provides auth views.
    |
    | Your User model must use Laravel\Sanctum\HasApiTokens and have
    | 'email' in $fillable.
    |
    */
    'auth' => [
        'enabled'      => false,
        'user_model'   => 'App\\Models\\User',
        'token_expiry' => 15, // minutes
        'home_route'   => 'home',
        'mail_subject' => 'Magic Link Login',
        'layout'       => 'layouts._auth',
    ],

    /*
    |--------------------------------------------------------------------------
    | Exception Handling
    |--------------------------------------------------------------------------
    |
    | Named route used for redirecting unauthenticated web requests (401).
    | Override if your login route has a different name.
    |
    */
    'exceptions' => [
        'login_route' => 'login',
    ],

];
