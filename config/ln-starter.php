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
    'ajax_layout' => 'ln-starter::layouts._ajax',

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
        'layout'       => 'ln-starter::layouts._auth',
    ],

];
