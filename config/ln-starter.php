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

    /*
    |--------------------------------------------------------------------------
    | Security Audit Logging & Observability
    |--------------------------------------------------------------------------
    |
    | Structured security events for the auth flow and for your application.
    | See docs/security-logging.md and docs/adr/0002-*.md.
    |
    | Nothing here may cause a raw secret or raw personal datum to be written:
    | identifiers are pseudonymized with a versioned HMAC, and event context is
    | filtered through an allow-list sanitizer.
    |
    */
    'logging' => [
        'enabled' => env('LN_SECURITY_LOG_ENABLED', true),

        // Channel for the default log sink. Null uses the app's default.
        'channel' => env('LN_SECURITY_LOG_CHANNEL'),

        // Used only when a sink itself fails. Keep it on the simplest,
        // most reliable channel you have.
        'fallback_channel' => env('LN_SECURITY_LOG_FALLBACK_CHANNEL'),

        // Response/request header carrying the correlation ID.
        'request_id_header' => env('LN_REQUEST_ID_HEADER', 'X-Request-Id'),

        // Sanitizer bounds. Context is allow-listed; these cap the shape.
        'max_context_depth' => (int) env('LN_SECURITY_LOG_MAX_DEPTH', 4),
        'max_context_fields' => (int) env('LN_SECURITY_LOG_MAX_FIELDS', 50),
        'max_value_length' => (int) env('LN_SECURITY_LOG_MAX_VALUE', 512),

        // Extra context keys your application emits. Unknown keys are dropped.
        'context_allow_list' => [],

        /*
        | Pseudonymization keys. Emails, IPs, and session IDs are never logged
        | raw; they become `<version>:<hmac>`. Rotate by adding a new version,
        | pointing `current` at it, and keeping the old key so historical rows
        | stay interpretable. Generate with:
        |   php -r "echo 'base64:'.base64_encode(random_bytes(32)),PHP_EOL;"
        */
        'pseudonym' => [
            'current' => env('LN_SECURITY_PSEUDONYM_ID', 'v1'),
            'keys' => [
                'v1' => env('LN_SECURITY_PSEUDONYM_KEY'),
            ],
        ],

        /*
        | Durable audit trail. Opt-in: enabling it requires publishing and
        | running the audit migration. Retention is enforced by
        | `php artisan ln-starter:security-audit-prune`.
        */
        'database' => [
            'enabled' => env('LN_SECURITY_AUDIT_DB', false),
            'connection' => env('LN_SECURITY_AUDIT_CONNECTION'),
            'retention_days' => (int) env('LN_SECURITY_AUDIT_RETENTION_DAYS', 90),
        ],
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
