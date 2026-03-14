# Auth Module (Passwordless / Magic Link)

## Overview

LN-Starter includes an opt-in passwordless authentication module. When enabled, it registers routes, loads migrations, and provides views for a complete magic link login flow — no passwords, no separate auth scaffolding.

## Prerequisites

Before enabling, ensure your project has:

1. **Laravel Sanctum** installed and configured
2. **User model** with `HasApiTokens` trait and `'email'` in `$fillable`
3. **Mail** configured (SMTP, Mailgun, etc.) — the module sends emails
4. **ln-acme** installed via npm (peer dependency for auth SCSS)

## Setup

### Step 1: Publish and edit config

```bash
php artisan vendor:publish --tag=ln-starter-config
```

In `config/ln-starter.php`, set `auth.enabled` to `true`:

```php
'auth' => [
    'enabled'      => true,
    'user_model'   => 'App\\Models\\User',  // your User model class
    'token_expiry' => 15,                    // magic link validity in minutes
    'home_route'   => 'home',               // route name after successful login
    'mail_subject' => 'Magic Link Login',   // email subject (translatable)
    'layout'       => 'layouts._auth', // auth page layout
],
```

### Step 2: Publish auth styles

```bash
php artisan vendor:publish --tag=ln-starter-auth-css
```

This copies `auth.scss` to `resources/scss/auth.scss`. Add it to your `vite.config.js`:

```js
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/scss/auth.scss',  // ← add this
                'resources/scss/app.scss',
                'resources/js/app.js',
            ],
        }),
    ],
});
```

Build frontend assets:

```bash
npm run build
```

The SCSS uses `@use 'ln-acme/scss/config/mixins'` and `ln-acme/scss/config/tokens` — `ln-acme` must be installed via npm.

### Step 3: Run migrations

```bash
php artisan migrate
```

This creates the `magic_link_tokens` table. To publish the migration for customization:

```bash
php artisan vendor:publish --tag=ln-starter-migrations
```

### Step 4: Configure middleware in bootstrap/app.php

```php
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware) {
    // 1. Bridge cookie → Authorization header (before Sanctum)
    $middleware->prepend(
        \LiveNetworks\LnStarter\Http\Middleware\AuthorizationFromCookie::class
    );

    // 2. Replace Laravel CSRF with LN-Starter's (skips for authenticated users)
    $middleware->web(replace: [
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class
            => \LiveNetworks\LnStarter\Http\Middleware\VerifyCsrfToken::class,
    ]);

    // 3. Exclude auth_token from cookie encryption
    $middleware->encryptCookies(except: ['auth_token']);
})
```

### Step 5: Define the home route

The module redirects to `route('home')` after login (configurable via `auth.home_route`). Make sure this route exists:

```php
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('home');
});
```

## Authentication Flow

```
┌─────────┐     POST /auth/magic-link      ┌─────────────┐
│  Login   │ ────────────────────────────► │  AuthController│
│  Form    │                               │  magicLink()  │
└─────────┘                               └──────┬────────┘
                                                  │
                                    1. Find/create user
                                    2. Create MagicLinkToken (15 min)
                                    3. Send MagicLinkMail
                                    4. Store IDs in session
                                    5. Redirect to /magic/wait
                                                  │
                                                  ▼
┌─────────────┐   fetch /magic/status    ┌─────────────┐
│  Wait Page  │ ◄──────────────────────► │  magicStatus()│
│  (polling)  │   every 2 seconds        │  (JSON)       │
└─────────────┘                          └──────┬────────┘
                                                │
        ┌───────────────────────────────────────┘
        │  Meanwhile, user opens email...
        ▼
┌─────────────┐   GET /magic/verify/{token}   ┌──────────────┐
│  Email Link │ ─────────────────────────────► │ magicVerify() │
│  (browser)  │                                │ marks approved│
└─────────────┘                                └──────────────┘
        │
        ▼
┌─────────────┐
│  Success    │  "You can close this window"
│  Page       │
└─────────────┘

        Back on the wait page...
        ┌───────────────────────────────────────┐
        │  Next poll detects approved=true       │
        │  → Creates Sanctum token               │
        │  → Sets auth_token cookie              │
        │  → Returns JSON with redirect URL      │
        │  → JS stores token in sessionStorage   │
        │  → Redirects to home                   │
        └───────────────────────────────────────┘
```

## Routes

All routes are registered in the `web` middleware group.

| Method | URI | Name | Middleware | Purpose |
|--------|-----|------|-----------|---------|
| GET | `/login` | `login` | web | Show login form |
| POST | `/auth/magic-link` | `login.magic-link` | web | Validate email, send magic link |
| GET | `/magic/wait` | `magic.wait` | web | Show "check your email" page |
| GET | `/magic/status` | `magic.status` | web | Poll for token approval (JSON) |
| GET | `/magic/verify/{token}` | `magic.verify` | web | Verify token from email link |
| POST | `/logout` | `logout` | web, auth:sanctum, disable-csrf | Revoke token, redirect |

## Configuration Reference

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `auth.enabled` | bool | `false` | Enable/disable the entire auth module |
| `auth.user_model` | string | `App\Models\User` | Fully qualified User model class |
| `auth.token_expiry` | int | `15` | Magic link validity in minutes |
| `auth.home_route` | string | `home` | Named route for post-login redirect |
| `auth.mail_subject` | string | `Magic Link Login` | Email subject (passed through `__()`) |
| `auth.layout` | string | `layouts._auth` | Blade layout for auth pages |

## Customizing Views

### Option A: Publish and edit

```bash
php artisan vendor:publish --tag=ln-starter-views
```

Published to `resources/views/vendor/ln-starter/`. Laravel automatically picks up vendor-published views over the package originals.

Files you can customize:

| File | Purpose |
|------|---------|
| `layouts/_auth.blade.php` | Auth page layout (add your logo, CSS, branding) |
| `auth/login.blade.php` | Login form |
| `auth/magic_wait.blade.php` | "Check your email" polling page |
| `auth/magic_success.blade.php` | "Login successful" confirmation |
| `auth/magic_error.blade.php` | "Link invalid/expired" error |
| `emails/magic-link.blade.php` | Email HTML template |

### Option B: Use your own layout

Point `auth.layout` to your project's layout:

```php
'auth' => [
    'layout' => 'layouts._auth',  // your own layout
],
```

All auth views use `@extends(config('ln-starter.auth.layout'))`, so they'll inherit your layout automatically.

## Translating

All user-facing strings use Laravel's `__()` helper. To translate:

1. Create language files in `lang/{locale}.json` or `lang/{locale}/` directories
2. Add translations for keys like:
   - `Login`, `E-mail address`, `Send magic link`
   - `Check email`, `Waiting for confirmation`
   - `Login successful!`, `Problem with the link`
   - `Hello`, `Sign in`, `If you did not request this link, ignore this email.`
   - etc.

The email subject is also translatable — set `auth.mail_subject` to the key, and it will be passed through `__()`.

## Security Notes

- **Token validity**: Tokens expire after `token_expiry` minutes (default 15) and can only be used once
- **Two-stage approval**: Token is created as `approved=false`, only set to `true` when the email link is clicked
- **Session tracking**: The wait page uses session to track which token belongs to which browser session
- **Cookie auth bridge**: The `auth_token` cookie is unencrypted and non-httpOnly so the client JS can read it. The `AuthorizationFromCookie` middleware converts it to a `Authorization: Bearer` header for Sanctum
- **Auto-create users**: `firstOrCreate` is used — any email submitted will create a user. If you need to restrict registration, override the `AuthController` or add validation logic
- **CSRF**: The login form uses `@csrf`. The logout route uses `disable-csrf` middleware since it's protected by `auth:sanctum`

## Overriding the Controller

If you need custom behavior (e.g., restrict user creation, add logging, change redirect logic), extend the controller in your project:

```php
namespace App\Http\Controllers;

use LiveNetworks\LnStarter\Http\Controllers\AuthController as BaseAuthController;

class AuthController extends BaseAuthController
{
    public function magicLink(Request $request)
    {
        // Custom logic before calling parent
        $validated = $request->validate(['email' => 'required|email']);

        // Only allow existing users (no auto-create)
        if (!User::where('email', $validated['email'])->exists()) {
            return back()->withErrors(['email' => __('Unknown email address.')]);
        }

        return parent::magicLink($request);
    }
}
```

Then define your own routes pointing to your controller, and disable the package routes by setting `auth.enabled` to `false` (or override specific routes in your `web.php` — project routes take priority).
