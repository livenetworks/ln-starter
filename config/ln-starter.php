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
        'sanctum.token'      => \LiveNetworks\LnStarter\Http\Middleware\AuthenticateWithSanctum::class,
        'cookie.auth'        => \LiveNetworks\LnStarter\Http\Middleware\AuthorizationFromCookie::class,
        'disable-csrf'       => \LiveNetworks\LnStarter\Http\Middleware\DisableCsrf::class,
        'ln.auth'            => \LiveNetworks\LnStarter\Http\Middleware\RequireAuthentication::class,
        'ln.locale'          => \LiveNetworks\LnStarter\Http\Middleware\SetLocale::class,
        'ln.locale.prepare'  => \LiveNetworks\LnStarter\Http\Middleware\PrepareLocale::class,
        'ln.locale.redirect' => \LiveNetworks\LnStarter\Http\Middleware\RedirectToLocale::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth Module (Passwordless / Magic Link)
    |--------------------------------------------------------------------------
    |
    | Enable the built-in passwordless authentication flow.
    | When enabled, the package registers login/logout routes,
    | loads the magic_login_attempts migration, and provides auth views.
    |
    | Your User model must be Laravel-authenticatable and expose its canonical
    | email address. Personal access tokens are not used by built-in auth v2.
    |
    */
    'auth' => [
        'enabled'             => false,
        'user_model'          => 'App\\Models\\User',
        'eligibility'         => \LiveNetworks\LnStarter\Support\DefaultAuthEligibility::class,
        'token_expiry'        => 15, // minutes
        'code_max_failures'   => 5,
        'response_floor_ms'   => 250,
        'response_jitter_ms'  => 50,
        'home_route'          => 'home',
        'mail_subject'        => 'Magic Link Login',
        'layout'              => 'layouts._auth',
        'peppers'             => [
            'current' => env('LN_AUTH_PEPPER_ID', 'v1'),
            'keys' => [
                'v1' => env('LN_AUTH_PEPPER'),
            ],
        ],
    ],

    'logging' => [
        'enabled' => true,
        'channel' => env('LN_SECURITY_LOG_CHANNEL'),
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
