# Middleware

## Overview

LN-Starter ships four middleware classes that handle authentication and CSRF management. These are the generic, reusable middleware — project-specific authorization (RBAC, role checks) stays in your application.

## AuthenticateWithSanctum

**Alias:** `sanctum.token`

Validates bearer tokens from the `Authorization` header using Laravel Sanctum's `PersonalAccessToken` model.

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

1. Extracts the bearer token from the `Authorization` header
2. Looks up the token in Sanctum's `personal_access_tokens` table
3. If valid and not revoked, sets the authenticated user on the request
4. If no token or invalid:
   - **`sanctum.token`** — the request proceeds unauthenticated (no abort — combine with Laravel's `auth` middleware if you need to enforce)
   - **`sanctum.token:required`** — returns `{"message": "Unauthenticated."}` with HTTP 401

### When to use

- `sanctum.token` — API routes that accept optional Sanctum tokens
- `sanctum.token:required` — API routes that must have a valid token (replaces the need to chain Laravel's `auth` middleware)
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

Marker middleware — does nothing itself. Its presence on a route signals `VerifyCsrfToken` to skip CSRF validation for that route.

```php
Route::middleware(['auth:sanctum', 'disable-csrf'])->group(function () {
    // Token-authenticated routes without CSRF
    Route::post('/api/members', [MemberController::class, 'store']);
});
```

### When to use

- API routes authenticated via bearer token (CSRF is unnecessary — the token IS the proof)
- Webhook endpoints
- Any route where CSRF protection is handled by other means

## VerifyCsrfToken

**No alias** — replaces Laravel's built-in CSRF middleware.

Extends Laravel's `ValidateCsrfToken` with two additional skip conditions:

1. **Authenticated users** — if `auth()->check()` is true, CSRF is skipped
2. **Routes with `disable-csrf`** — if the route has the `DisableCsrf` middleware assigned

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

Skipping CSRF for authenticated users is intentional: in this architecture, the same URL serves both form submissions (browser, with session) and API requests (token auth). Authenticated sessions already have a validated identity — double-checking CSRF adds friction without meaningful security for the dual-mode pattern.

## Middleware stack order

For routes that serve both browser and API:

```php
Route::middleware([
    'cookie.auth',       // 1. Bridge cookie → header
    'sanctum.token',     // 2. Validate token
    // 3. VerifyCsrfToken runs in web group (skips if authenticated)
])->group(function () {
    Route::resource('members', MemberController::class);
});
```
