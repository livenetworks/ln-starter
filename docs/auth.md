# Auth module (passwordless link + code)

LN-Starter provides an opt-in, passwordless authentication flow for existing
users. Every request produces two independent proofs with one shared lifetime:

- a high-entropy link for the device that opens the email;
- a six-digit code for the browser that requested the login.

The first successfully consumed proof wins. Consumption authenticates a normal
Laravel `web` session; the built-in flow never creates a Sanctum personal access
token or an `auth_token` cookie.

The complete security design and acceptance criteria live in
[`adr/0001-magic-link-authentication-v2.md`](adr/0001-magic-link-authentication-v2.md)
and [`auth-v2-test-matrix.md`](auth-v2-test-matrix.md).

## Prerequisites

- PHP 8.3 or newer and a supported Laravel version.
- A Laravel-authenticatable user model with an email attribute.
- A persistent session driver and an asynchronous queue in production.
- Working mail and queue workers.
- A random HMAC pepper of at least 32 bytes.

`database`, Redis, or another shared session store is recommended for multiple
application nodes. Production boot fails closed for `array`/`cookie` sessions,
the `sync` queue, a process-local/non-locking session block cache, a missing
pepper, or an undersized pepper. Configure `session.block_store` when the
default cache store is not a shared lock provider.

## Setup

Publish the configuration and set the v2 values:

```bash
php artisan vendor:publish --tag=ln-starter-config
```

```dotenv
LN_AUTH_PEPPER_ID=v1
LN_AUTH_PEPPER=base64:REPLACE_WITH_AT_LEAST_32_RANDOM_BYTES
QUEUE_CONNECTION=database
SESSION_DRIVER=database
```

Generate a suitable pepper:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Relevant configuration:

```php
'auth' => [
    'enabled' => true,
    'user_model' => App\Models\User::class,
    'eligibility' => App\Auth\LoginEligibility::class,
    'token_expiry' => 15,
    'code_max_failures' => 5,
    'response_floor_ms' => 250,
    'response_jitter_ms' => 50,
    'home_route' => 'home',
    'mail_subject' => 'Magic Link Login',
    'layout' => 'layouts._auth',
    'peppers' => [
        'current' => env('LN_AUTH_PEPPER_ID', 'v1'),
        'keys' => ['v1' => env('LN_AUTH_PEPPER')],
    ],
],
```

Publish or load the additive migration, migrate, and run a worker:

```bash
php artisan vendor:publish --tag=ln-starter-migrations
php artisan migrate
php artisan queue:work
```

The migration creates `magic_login_attempts`. LN-Starter does not replace the
consumer's users migration and does not require `HasApiTokens`. The optional
Sanctum migration has its own `ln-starter-sanctum-migrations` publish tag.

Protect the destination route with the session guard:

```php
Route::middleware('auth:web')->get('/', DashboardController::class)->name('home');
```

Render logout as a CSRF-protected POST form:

```blade
<x-ln.logout-form class="nav-logout">{{ __('Sign out') }}</x-ln.logout-form>
```

## Eligibility policy

The default `DefaultAuthEligibility` permits any resolved user. Applications
with status, tenant, or suspension rules should configure an implementation of
`LiveNetworks\LnStarter\Contracts\AuthEligibility`:

```php
final class LoginEligibility implements AuthEligibility
{
    public function allows(Authenticatable $user): bool
    {
        return $user->is_active && ! $user->is_suspended;
    }
}
```

Eligibility and the canonical email key are checked both when the queued job
creates the attempt and again inside the locked consume transaction.

## Flow

1. `POST /auth/magic-link` always returns the same public response. It creates
   opaque proofs and dispatches an encrypted queue job.
2. The job resolves eligibility, stores only one-way hashes, and sends the raw
   link and six-digit code only to the eligible user's email.
3. A link `GET` never authenticates. It exchanges the URL secret for a bounded,
   short-lived session context and redirects to a token-free confirmation URL.
4. A CSRF-protected confirmation `POST`, or the requesting browser's code POST,
   locks and consumes the attempt.
5. The winner is marked consumed, sibling pending attempts are revoked, the web
   session ID is regenerated, and the user is redirected to `home_route`.

Opening the email link on a phone signs in the phone after explicit confirmation
and makes the desktop code unusable. To sign in the desktop, leave the link
unconfirmed and enter the code there.

## Routes

All state-changing routes use the `web` middleware group and normal CSRF.
Static routes are intentionally registered before the wildcard token route.

| Method | URI | Name | Purpose |
|---|---|---|---|
| GET | `/login` | `login` | Login form |
| POST | `/auth/magic-link` | `login.magic-link` | Generic response and queued processing |
| GET | `/auth/magic/code` | `auth.magic.code.form` | Code entry in the requesting session |
| POST | `/auth/magic/code` | `auth.magic.code` | Consume the code |
| GET | `/auth/magic/{token}` | `auth.magic.link.open` | Exchange URL proof for a session context |
| GET | `/auth/magic/confirm/{context}` | `auth.magic.link.confirm` | Token-free confirmation page |
| POST | `/auth/magic/confirm/{context}` | `auth.magic.link.consume` | Consume the link proof |
| POST | `/logout` | `logout` | Invalidate the web session and rotate CSRF |

For one compatibility release the v1 endpoints remain reachable but inert.
`GET /magic/wait` redirects to the v2 login page with the generic message;
`GET|POST /magic/status` returns HTTP 410 with
`{"ok":false,"error":"No session","upgrade_required":true}`, which is what
makes an old polling script stop rather than retry a 404 loop. The POST
variant keeps normal CSRF protection. Neither issues credentials. Old
`auth.magic.show`/`auth.magic.consume` route names are deliberately absent so a
secret can never be appended to a query string by an old helper call.

## Rate limits and concurrency

Request and verification paths use layered email/session/IP/context throttles.
The code counter increases only after a failed verification. The fifth correct
entry is accepted; the fifth wrong entry locks the code proof while the link can
still succeed. Database row locks and Laravel route session locks guarantee that
only one concurrent proof can win.

Use a row-locking production database. SQLite tests cover contracts, while the
repository CI also runs the concurrency suite against MySQL.

## Secret storage and pepper rotation

The database stores HMAC/SHA-256 digests, never raw email, link token, code, or
session nonce. Model serialization also hides all proof hashes.

To rotate a pepper without invalidating outstanding attempts:

1. Add the new key while retaining the old key.
2. Change `peppers.current` to the new ID.
3. Wait longer than `token_expiry` plus queue delay.
4. Remove the old key.

Attempts retain their `pepper_id`; a referenced key that is missing fails closed
and emits a security event.

## Upgrade from auth v1

Before enabling v2:

```bash
php artisan ln-starter:auth-v2-audit
php artisan ln-starter:auth-v2-readiness
php artisan ln-starter:auth-v2-cutover --force
php artisan migrate
```

The audit rejects published v1 auth views that still poll status endpoints, use
old route names/session keys, or omit the code. Port or republish those views.
The readiness command validates production queue/session locks and confirms
that every unexpired pending attempt still has its referenced pepper key.
The cutover command explicitly invalidates pending v1 proofs; already-issued v1
links cannot be preserved safely across the state-machine change.

Install preflight performs the same readiness and view checks before publishing,
preventing a half-published upgrade. Expired or terminal records can later be
cleaned with:

```bash
php artisan magic-login-attempts:cleanup --hours=24
```

The legacy alias `magic-link-tokens:cleanup` remains available for one release.

## Views and localization

Publish views with `php artisan vendor:publish --tag=ln-starter-views`. The v2
surface contains `auth/login.blade.php`, `auth/magic_code.blade.php`,
`auth/magic.blade.php`, and `emails/magic-link.blade.php`. Published v1
`magic_wait.blade.php`/`magic_success.blade.php` files must be removed or ported.

With multiple `app.languages`, the same named routes are localized under
`/{locale}`. `ln.locale.prepare` seeds the URL default before routing so links
generated by queued mail keep the request locale.

## Security logging

The whole flow is instrumented through the package's security event pipeline:
request received/accepted, rate limiting, delivery queued/succeeded/failed, link
opened, proof accepted/rejected/replayed/expired, code lock, sibling revocation,
and session creation/termination — each with a real `duration_ms` and a
correlation ID that the queued delivery job inherits.

Every event carries a versioned envelope with a constrained reason code, and a
pseudonymous `principal_key` instead of an email address. Raw tokens, codes,
cookies, authorization headers, session IDs, request bodies, and email addresses
are never written to any sink.

Set `LN_SECURITY_LOG_CHANNEL` to a dedicated structured channel, and enable the
opt-in database audit trail with `LN_SECURITY_AUDIT_DB=true`.

Full reference, event catalog, sink extension, pepper rotation, and retention:
[`security-logging.md`](security-logging.md).

## Customizing behavior

Prefer the eligibility contract and configuration hooks over subclassing the
controller. If an application owns its full auth controller/routes, set
`ln-starter.auth.enabled=false`; the generic locale and bearer-API middleware
remain available independently.
