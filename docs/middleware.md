# Middleware

## Overview

LN-Starter ships four middleware classes that handle authentication and CSRF management. These are the generic, reusable middleware — project-specific authorization (RBAC, role checks) stays in your application.

## AuthenticateWithSanctum

**Alias:** `sanctum.token`

Validates bearer tokens by delegating to Laravel Sanctum's official `sanctum` guard. This preserves Sanctum's expiry and provider checks, `currentAccessToken()`, authentication events, and `last_used_at` tracking.

```php
Route::middleware(['sanctum.token'])->group(function () {
    Route::get('/api/members', [MemberController::class, 'index']);
});
```

Supports an optional `:required` parameter to enforce authentication:

```php
// Optional — validate if present, don't block
Route::middleware(['sanctum.token'])->group(function () {
    Route::get('/api/members', [MemberController::class, 'index']);
});

// Required — return 401 JSON if not authenticated
Route::middleware(['sanctum.token:required'])->group(function () {
    Route::get('/api/me', [ProfileController::class, 'show']);
});
```

### How it works

1. Resolves Laravel's `sanctum` guard
2. Lets Sanctum parse and validate the bearer token
3. If valid, makes Sanctum the active guard and sets the authenticated request user
4. If no token or invalid:
   - **`sanctum.token`** — the request proceeds unauthenticated (no abort — combine with Laravel's `auth` middleware if you need to enforce)
   - **`sanctum.token:required`** — returns `{"message": "Unauthenticated."}` with HTTP 401

### When to use

- `sanctum.token` — API routes that accept optional Sanctum tokens
- `sanctum.token:required` — backwards-compatible required mode; prefer Laravel's built-in `auth:sanctum` middleware for new routes
- Combine with `cookie.auth` for hybrid cookie/token auth

## AuthorizationFromCookie

**Alias:** `cookie.auth`

Reads a Sanctum token from the `auth_token` cookie and sets it as the `Authorization: Bearer` header. This bridges cookie-based clients (browser JS) with Sanctum's token auth.

```php
Route::middleware(['cookie.auth', 'sanctum.token'])->group(function () {
    // Both cookie-based and header-based auth work here
});
```

### How it works

1. Checks for `auth_token` in cookies
2. If present and no `Authorization` header exists, sets `Authorization: Bearer {token}`
3. Passes the request to the next middleware (typically `AuthenticateWithSanctum`)

### When to use

- When your frontend stores Sanctum tokens in cookies (e.g., after login)
- Always place BEFORE `sanctum.token` in the middleware stack

## DisableCsrf

**Alias:** `disable-csrf`

Marker middleware — does nothing itself. `VerifyCsrfToken` honors only the `bearer` mode and only when the request contains an explicit `Authorization: Bearer ...` header.

```php
Route::middleware(['auth:sanctum', 'disable-csrf:bearer'])->group(function () {
    // Authorization-header bearer-token routes
    Route::post('/api/members', [MemberController::class, 'store']);
});
```

### When to use

- API routes authenticated exclusively via an `Authorization` bearer token
Do not use this marker for webhooks; register webhook exclusions through Laravel's CSRF exception configuration and independently verify the provider signature. A bare `disable-csrf`, a session-authenticated request, or a bearer header derived from the `auth_token` cookie does not bypass CSRF.

## VerifyCsrfToken

**No alias** — replaces Laravel's built-in CSRF middleware.

Extends Laravel's `ValidateCsrfToken` with one explicit skip condition: routes carrying `disable-csrf:bearer` while the request also carries an Authorization bearer header. Authentication by itself never disables CSRF protection.

### Registration

In your `bootstrap/app.php` or `Http/Kernel.php`, replace Laravel's default CSRF middleware with this one:

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(replace: [
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class
            => \LiveNetworks\LnStarter\Http\Middleware\VerifyCsrfToken::class,
    ]);
})
```

### Design rationale

Authentication proves the user's identity; CSRF protection proves that a state-changing browser request originated from the application. Cookie and session authentication therefore remain protected. Only routes that do not rely on automatically submitted browser credentials should opt out explicitly.

## Middleware stack order

For routes that serve both browser and API:

```php
Route::middleware([
    'cookie.auth',       // 1. Bridge cookie → header
    'sanctum.token',     // 2. Validate token
    // 3. VerifyCsrfToken runs in web group; forms must submit a CSRF token
])->group(function () {
    Route::resource('members', MemberController::class);
});
```
