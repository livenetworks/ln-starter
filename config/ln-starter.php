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

];
