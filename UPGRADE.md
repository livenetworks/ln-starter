# Upgrade notes

## Unreleased — security audit logging & observability

Nothing is required to keep working: the log sink is on by default, the database
sink is opt-in, and `SecurityEventLogger::record()` keeps its previous shape.
The items below are worth acting on.

### Event names changed

Auth events were renamed to a canonical catalog. If you match on event names in
a SIEM, an alert, or a log query, update them:

| Before | Now |
|---|---|
| `auth.magic.proof.consumed` | `auth.magic.proof.accepted` |
| `auth.magic.attempt.expired` | `auth.magic.proof.expired` |
| `auth.magic.delivery.sent` | `auth.magic.delivery.succeeded` |
| `auth.magic.request.rate_limited` | `auth.magic.rate_limited` |
| `auth.magic.login.succeeded` | `auth.session.created` |
| `auth.magic.login.failed` | `auth.session.created` with `outcome=error` |
| `auth.logout.succeeded` | `auth.session.terminated` |
| `auth.magic.pepper.unavailable` | `security.readiness.failed` with `reason_code=pepper_unavailable` |

The payload also changed shape: fields that used to live in a flat context —
`attempt_id`, `outcome`, `reason`, `user_id`, `duration_ms` — are now real
envelope fields, and `user_id` is replaced by a pseudonymous `principal_key`.

### Context keys are allow-listed

If your application passes custom keys to `SecurityEventLogger::record()`, they
are now dropped unless registered:

```php
// config/ln-starter.php
'logging' => [
    'context_allow_list' => ['tenant_id', 'plan'],
],
```

This is deliberate — an unregistered key cannot leak.

### Correlation middleware on your own routes

Package auth routes already carry it. To get request IDs elsewhere:

```php
// bootstrap/app.php
$middleware->web(append: [
    \LiveNetworks\LnStarter\Http\Middleware\AssignRequestId::class,
]);
```

### Optional: pseudonymization key

Without configuration, pseudonym keys are derived from `APP_KEY`. That is a real
secret and safe, but rotating `APP_KEY` then changes every pseudonym. To decouple
the two lifecycles:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)),PHP_EOL;"
```

```env
LN_SECURITY_PSEUDONYM_KEY=base64:...
```

### Optional: durable audit trail

```bash
php artisan vendor:publish --tag=ln-starter-security-migrations
php artisan migrate
```

```env
LN_SECURITY_AUDIT_DB=true
```

Then schedule retention:

```php
Schedule::command('ln-starter:security-audit-prune --force')->dailyAt('03:15');
```

### Verify before deploying

```bash
php artisan ln-starter:auth-v2-readiness
```

In production this now also fails when the database cannot provide
transactional row locking (SQLite, or a non-InnoDB attempts/audit table on
MySQL/MariaDB), because `lockForUpdate()` is silently a no-op there and
single-use proof consumption would not be atomic.

## Production requirements added in this release

Readiness now refuses to boot in production unless the deployment is safe.
Each of these was previously unchecked:

| Requirement | Why |
|---|---|
| `APP_URL` starts with `https://` | The magic link inherits it; http means the proof travels in the clear |
| `SESSION_SECURE_COOKIE=true` | After login the session cookie *is* the credential |
| Session cookie is http-only | Keeps it out of reach of page scripts |
| `session.same_site` is `lax` or `strict` | `none` sends the credential cross-site |
| `session.domain` unset, or exactly the `APP_URL` host | RFC 6265 ignores the leading dot, so `example.com` is as wide as `.example.com`: any `Domain` attribute sends the session credential to every subdomain |
| Configured, non-`sync` queue connection | Delivery is queued; a missing worker fails logins silently |
| Configured mailer | Same reason |
| Transactional row locking | SQLite and MyISAM break single-use consumption with no error |

Behind a TLS-terminating proxy, configure TrustProxies so generated URLs are
`https`. Run `php artisan ln-starter:auth-v2-readiness` before sending traffic.

## Running the test suite against a server database

The suite resets server-backed test databases between test classes. That is now
opt-in and allow-listed:

```bash
LN_STARTER_ALLOW_TEST_DB_RESET=1 DB_CONNECTION=mysql DB_DATABASE=ln_starter_scratch vendor/bin/phpunit
```

The variable must be exactly `1`, and the database must be named
`ln_starter_scratch` or `ln_starter_ci`. The generic name `ln_starter` is not
accepted — it is too plausible as a real local database. Anything unrecognised
refuses rather than guessing. See `docs/deployment.md`.
