# Consumer adoption guide

The entry point for putting LN-Starter auth v2 and its security event pipeline
into a Laravel application.

This document is deliberately thin where another document is already
authoritative. It links rather than restates, because two copies of an upgrade
procedure diverge and the wrong one gets followed.

| Topic | Authoritative document |
|---|---|
| Auth flow, routes, eligibility, customisation | [`auth.md`](auth.md) |
| Event envelope, catalog, privacy rules, sinks | [`security-logging.md`](security-logging.md) |
| Upgrading an existing installation | [`../UPGRADE.md`](../UPGRADE.md) |
| Deploy checklist, queue runbook, rollback | [`deployment.md`](deployment.md) |
| Monitoring baseline and incident runbooks | [`runbooks.md`](runbooks.md) |
| Item-by-item go-live verification | [`consumer-go-live-checklist.md`](consumer-go-live-checklist.md) |
| Why the design is what it is | [ADR 0001](adr/0001-magic-link-authentication-v2.md), [0002](adr/0002-security-audit-logging-and-observability.md), [0003](adr/0003-auth-v2-production-and-release-contract.md), [0004](adr/0004-versioning-release-and-distribution.md) |
| What changed in 2.0.0 | [`releases/2.0.0.md`](releases/2.0.0.md) |

## Supported combinations

| Target | Status |
|---|---|
| Laravel 13, Laravel 12 | Supported |
| Laravel 11 | Compatibility only — end of life, not a production target |
| PHP 8.3 / 8.4 / 8.5 | Supported |
| MySQL (InnoDB), PostgreSQL | Supported |
| SQLite | Development and tests only — production readiness refuses it |

SQLite is refused for a specific reason, not out of preference: `lockForUpdate()`
is silently a no-op there, so single-use proof consumption would not be atomic
and two concurrent requests could both win.

## 1. Fresh application adoption

> **Availability.** These commands resolve `livenetworks/ln-starter:^2.0` from
> Packagist. At the time of writing, 2.0.0 is qualified but **not published** —
> publication is owner-gated. Until it is, install from a VCS or path
> repository, and treat the Packagist step as pending.

**Set `LN_AUTH_PEPPER` before `composer require`.** Package discovery boots the
service provider during the require itself, and the provider validates auth
configuration — a missing pepper fails the require, not a later artisan call.
Setting `LN_SECURITY_PSEUDONYM_KEY` at the same time is recommended but not
required; see the environment contract below.

```bash
# .env — before anything else
# LN_AUTH_PEPPER=base64:<32 random bytes, base64-encoded>
# LN_SECURITY_PSEUDONYM_KEY=base64:<32 random bytes, base64-encoded>

composer require livenetworks/ln-starter:^2.0
php artisan vendor:publish --tag=ln-starter-config --no-interaction
```

The installer normally publishes the config, but the auth flag must be enabled
*before* the installer and readiness command run. Publish the config explicitly,
then enable the module. `ln-starter.auth.enabled` defaults to
**`false`**, and this is the step most easily missed, because skipping it fails
*quietly*: both `ln-starter:install` and `ln-starter:auth-v2-readiness` skip
every auth check when the module is off. You get a green readiness run, no auth
routes, and no indication that anything is missing.

```php
// config/ln-starter.php
'auth' => [
    'enabled' => true,
    // ...
],
```

```bash
php artisan ln-starter:install
php artisan migrate
php artisan ln-starter:auth-v2-readiness
php artisan route:list --name=auth.magic.link.open
```

The final route check is package-specific; a generic `login` route may belong to
the consumer application and is not proof that LN-Starter auth is enabled.
`ln-starter:install` publishes the remaining assets (and config when it is not
already present), runs the upgrade audit, and injects the auth SCSS entry into
`vite.config.js`. It is idempotent: running it twice does not overwrite anything
you have edited.

Then, in the order that matters operationally:

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache
# restart queue workers — they hold the old code in memory
npm run build            # optional; see "Frontend assets" below
```

Finally, perform a **real login** end to end — request a link, open it, confirm,
land authenticated — before considering the deployment done. Nothing else
exercises the queue, the mailer, the session driver and the database lock
together.

### Frontend assets

The auth pages render **before** any frontend build. The shipped layout asks
your application's own Vite service for the tags, so a custom hot file, build
directory or manifest name is honoured, and it emits nothing when the entries
have not been built. A fresh install — or a deploy where the asset build was
skipped — gets an unstyled but fully working login page rather than HTTP 500 on
the entry point of the auth flow.

## 2. Existing application adoption

The full procedure is in [`../UPGRADE.md`](../UPGRADE.md) and is not repeated
here. The shape of it, and the parts people get wrong:

1. **Back up the database.** Step 8 destroys data and cannot be undone.
2. **`LN_AUTH_PEPPER` into `.env` first** — before Composer, for the reason above.
3. **`composer require livenetworks/ln-starter:^2.0`.** This must come before
   the audit: `ln-starter:auth-v2-audit` does not exist in 1.x.
4. **Audit published views** — it names every stale path and exits non-zero
   until none remain.
5. **Delete `magic_wait.blade.php` and `magic_success.blade.php`.** There is
   nothing to port: auth v2 has neither a wait page nor a success page. Port
   your customisations of the views that still exist.
6. **`php artisan ln-starter:install`** — required, and it refuses to run while
   a stale v1 view is still published.
7. **`php artisan migrate`**, then **`ln-starter:auth-v2-readiness`**.
8. **`php artisan ln-starter:auth-v2-cutover --force`** — invalidates pending v1
   proofs. **Irreversible.** Users mid-login request a new link.
9. **Caches, workers, smoke test.**

### Rollback limitations

Downgrading the package does **not** undo step 8. Once the cutover has run,
those proofs are gone; affected users simply request a new link. The
`magic_login_attempts` and `ln_security_audit_events` tables and the additive
`users` columns are additive — leave them in place, a downgraded 1.x
installation ignores them. Full procedure in [`deployment.md`](deployment.md).

## 3. Environment contract

Placeholders below are obviously fake. Generate real values as 32 random bytes,
base64-encoded, and store them in your secret manager — never in the repository.

| Variable | Required | Default | Production expectation | Rotation impact | Failure mode | Verify with |
|---|---|---|---|---|---|---|
| `LN_AUTH_PEPPER` | **Yes** | none | 32 random bytes, base64 | Rotating without keeping the old key under `auth.peppers.keys` makes pending attempts unverifiable | Provider throws at boot — `composer require` itself fails | `ln-starter:auth-v2-readiness` |
| `LN_AUTH_PEPPER_ID` | No | `v1` | Bump when adding a new pepper version | Points at which key signs new proofs | Readiness fails if the id is absent from the key set | `ln-starter:auth-v2-readiness` |
| `LN_SECURITY_PSEUDONYM_KEY` | No | derived from `APP_KEY` | Set explicitly | Changes every `principal_key`; historical correlation breaks | Silent — audit history stops correlating | Compare `principal_key` before/after a known login |
| `LN_SECURITY_PSEUDONYM_ID` | No | `v1` | Bump alongside a key rotation | Marks which key produced a digest | Old digests become unresolvable if the key is dropped | `ln-starter:auth-v2-readiness` |
| `LN_SECURITY_LOG_ENABLED` | No | `true` | `true` | — | Disabling loses the audit trail; auth keeps working | Trigger a login, check the channel |
| `LN_SECURITY_LOG_CHANNEL` | No | default channel | A dedicated channel | — | Events land in the app log instead | `config('ln-starter.logging.channel')` |
| `LN_SECURITY_LOG_FALLBACK_CHANNEL` | No | unset — the application's default logger is used | Set a channel explicitly if the primary can fail | — | Fallback writes land in the app log rather than a dedicated channel | `ln-starter:auth-v2-readiness` reports the resolved value |
| `LN_SECURITY_LOG_MAX_DEPTH` | No | `4` | Leave as is | — | Deeper context is truncated | — |
| `LN_SECURITY_LOG_MAX_FIELDS` | No | `50` | Leave as is | — | Extra fields dropped | — |
| `LN_SECURITY_LOG_MAX_VALUE` | No | `512` | Leave as is | — | Long values truncated | — |
| `LN_SECURITY_AUDIT_DB` | No | `false` | `true` if you want a queryable trail | — | No `ln_security_audit_events` rows | `ln-starter:auth-v2-readiness` |
| `LN_SECURITY_AUDIT_CONNECTION` | No | default connection | A connection that survives app rollback | — | Readiness probes the wrong database | `ln-starter:auth-v2-readiness` |
| `LN_SECURITY_AUDIT_RETENTION_DAYS` | No | `90` | Your legal retention window | — | Unbounded table growth if pruning never runs | `ln-starter:security-audit-prune --dry-run` |
| `LN_REQUEST_ID_HEADER` | No | `X-Request-Id` | Match your edge proxy | — | Correlation ids not inherited from the edge | Send the header, check `request_id` |

Application-level settings the package requires but does not own — `APP_URL`,
`session.*`, `queue.default`, `mail.default`, database engine — are enforced by
`ln-starter:auth-v2-readiness` and listed in
[`consumer-go-live-checklist.md`](consumer-go-live-checklist.md).

## 4. Authentication contract

What the package guarantees, and where your application's responsibility starts.

### What the package provides

- **Laravel `web` session authentication.** Not a token, not a custom guard. A
  successful login is an ordinary authenticated session, so your existing
  `auth` middleware, gates and policies work unchanged.
- **A single-use, row-locked proof.** Consumption happens inside a transaction
  under `lockForUpdate()`. Under concurrency exactly one request wins; the loser
  is reported as a replay, not as a second success.
- **A read-only link GET.** `GET /auth/magic/{token}` never consumes the proof
  and never authenticates. It establishes a session-bound confirmation context,
  applies the open throttle, emits an audit event, and redirects to a
  token-free confirmation URL — so the raw token does not reach the address bar
  of the confirming request, its referrer, or that step's browser history.
- **CSRF protection on every state change.** Code submission, link confirmation
  and logout are all CSRF-protected. A bearer header does not exempt a
  session-authenticated request.
- **Session invalidation on logout**, with CSRF token rotation.
- **Layered throttling** on email, IP, session, proof and confirmation context.
- **Enumeration-resistant responses**: the reply to a link request is identical
  whether or not the account exists.

### What your application must provide

- A `User` model reachable through `ln-starter.auth.user_model`.
- A mailer that actually delivers, and a queue connection that is not `sync` —
  `sync` defeats the response-timing property above.
- A session driver, a row-locking database, and HTTPS.
- Any authorisation beyond "is authenticated". **RBAC is not part of this
  package.**

### What you may override

Published Blade views under `resources/views/vendor/ln-starter/` are never
overwritten by the installer, and `ln-starter.auth.layout` selects the layout.
Eligibility is a contract — implement `AuthEligibility` to decide who may
receive a link. See [`auth.md`](auth.md#customizing-behavior).

### What you must not override

Do not reimplement proof consumption, weaken CSRF on the auth routes, or
reintroduce a GET endpoint that changes authentication state. Those are the
invariants ADR 0001 exists to protect.

Per [ADR 0004](adr/0004-versioning-release-and-distribution.md), the stable
public API is route **names**, config keys, middleware aliases, publish tags,
published view names, migrations, security event names and their envelope, and
command signatures. Everything else — including the internals of
`MagicLoginStateMachine` and the `Security\*` classes beyond
`SecurityEventLogger` and `SecurityAuditSink` — may change in a minor release.
