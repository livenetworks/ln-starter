# Deployment, runbook and rollback

Operational companion to [ADR 0003](adr/0003-auth-v2-production-and-release-contract.md).

## Supported versions

| | Status |
|---|---|
| Laravel 13 (PHP 8.3–8.5) | Supported |
| Laravel 12 (PHP 8.3–8.5) | Supported |
| Laravel 11 | **Compatibility only — end of life, not security-supported** |
| MySQL / MariaDB with InnoDB | Supported |
| PostgreSQL | Supported |
| SQLite | Development only — **refused in production** |

SQLite is refused because `lockForUpdate()` is a no-op there, and single-use
proof consumption depends on real row locking.

## Fresh install

```bash
composer require livenetworks/ln-starter
php artisan vendor:publish --tag=ln-starter-config
php artisan ln-starter:install
php artisan migrate
```

Enable the module and set the secrets:

```env
APP_URL=https://app.example.com

DB_CONNECTION=pgsql
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=redis
MAIL_MAILER=smtp

LN_AUTH_PEPPER="base64:..."             # php -r "echo 'base64:'.base64_encode(random_bytes(32));"
LN_SECURITY_PSEUDONYM_KEY="base64:..."
```

```php
// config/ln-starter.php
'auth' => ['enabled' => true],
```

Then verify before sending traffic:

```bash
php artisan ln-starter:auth-v2-readiness
```

## Optional audit trail

```bash
php artisan vendor:publish --tag=ln-starter-security-migrations
php artisan migrate
```

```env
LN_SECURITY_AUDIT_DB=true
LN_SECURITY_AUDIT_RETENTION_DAYS=90
```

Schedule retention — the table grows with every security event:

```php
// routes/console.php
Schedule::command('ln-starter:security-audit-prune --force')->dailyAt('03:15');
```

## Deploy checklist

- [ ] Database backup taken **before** `migrate`
- [ ] `APP_URL` is `https`; TrustProxies configured if TLS terminates upstream
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_DRIVER` is server-side and lock-capable
- [ ] Queue connection is not `sync` and a worker is actually running
- [ ] Mailer configured and deliverable
- [ ] `LN_AUTH_PEPPER` and `LN_SECURITY_PSEUDONYM_KEY` set and in secret storage
- [ ] MySQL/MariaDB: attempt and audit tables are InnoDB
- [ ] `php artisan migrate --force`
- [ ] `php artisan config:cache && php artisan route:cache && php artisan view:cache`
- [ ] `php artisan ln-starter:auth-v2-readiness` passes
- [ ] **Queue workers restarted** (`php artisan queue:restart`)
- [ ] One real login exercised end to end
- [ ] `auth.session.created` visible in the log channel

## Queue worker runbook

Magic-link delivery runs on the queue. A stopped worker means logins fail
silently: the public response is generic by design, so nobody sees an error.

```bash
php artisan queue:work --queue=default --tries=3 --max-time=3600
```

- Supervise the worker (systemd, Supervisor, Horizon).
- Run `php artisan queue:restart` on **every** deploy — workers hold old code.
- The delivery job implements `ShouldBeEncrypted`; the payload contains the link
  token and code, so the queue backend must be treated as sensitive storage.
- Failed jobs: `php artisan queue:failed`. A failure means the email never went
  out. The attempt still expires on its own.
- Alert on `auth.magic.delivery.failed` and on `security.audit.sink_failed`.
- Delivery is **at-most-once**; auditing makes no exactly-once claim.

## Monitoring

| Signal | Meaning |
|---|---|
| `auth.magic.delivery.failed` | Mail transport is broken — logins are failing |
| `auth.magic.rate_limited` spike | Targeted flood or a misbehaving client |
| `auth.magic.proof.replayed` spike | Link reuse, or a race under load |
| `security.audit.sink_failed` | Audit pipeline degraded; auth still up |
| `security.readiness.failed` | Configuration drift — usually a rotated pepper |
| `duration_ms` on `auth.session.created` | Login latency |

Pivot on `correlation_id` to reconstruct a full attempt across HTTP and queue.

## Pepper rotation

```php
'pseudonym' => [
    'current' => 'v2',
    'keys' => [
        'v1' => env('LN_SECURITY_PSEUDONYM_KEY_V1'),   // keep while v1 rows exist
        'v2' => env('LN_SECURITY_PSEUDONYM_KEY_V2'),
    ],
],
```

Keep the retired key for at least the audit retention window. The same applies
to `ln-starter.auth.peppers`: removing a key that a pending attempt references
makes that attempt unverifiable, and readiness will refuse to boot — which is
the intended, loud failure.

## Rollback

Auth v2 replaced the v1 polling flow, so rolling back is an operational
procedure, not just a version change.

1. **Restore the previous release** and run `php artisan queue:restart`.
2. **Leave `magic_login_attempts` in place.** It is additive and harmless. Do
   not drop it — a re-deploy forward would lose in-flight attempts.
3. **Pending attempts do not survive.** Users request a new link. Attempts are
   short-lived by design, so the blast radius is one expiry window.
4. **If a pepper was rotated in the release being rolled back**, restore the
   previous key alongside it or every pending attempt becomes unverifiable.
5. **The audit table is independent.** It can stay; drop it separately only if
   you are abandoning the sink.
6. **Clear caches**: `config:clear`, `route:clear`, `view:clear`, then re-cache.
7. Re-run `ln-starter:auth-v2-readiness` on the rolled-back release.

What rollback cannot undo: personal access tokens are not reinstated, because
auth v2 never minted any.

## Local test database

The suite drops all tables between test classes on server-backed connections.
That is opt-in and allow-listed by name so it can never reach a real database:

```bash
mysql -e "CREATE DATABASE ln_starter_scratch"

LN_STARTER_ALLOW_TEST_DB_RESET=1 \
DB_CONNECTION=mysql DB_DATABASE=ln_starter_scratch DB_USERNAME=root \
vendor/bin/phpunit
```

Without `LN_STARTER_ALLOW_TEST_DB_RESET=1`, or with a database name that is not
allow-listed, the suite refuses to run rather than guessing. See
`tests/TestDatabaseGuard.php`.

## Release qualification

```bash
composer validate
composer test                     # includes the source-hygiene gate:
                                  # no control characters in tracked files
composer verify-artifact          # fails unless it installs from the archive
                                  # add --allow-offline only to acknowledge a
                                  # partial run; it does not qualify a release
php scripts/consumer-install.php --laravel=13
php scripts/consumer-upgrade.php --laravel=13
```

CI additionally runs the supported lanes across SQLite, MySQL and PostgreSQL,
and fails a server-backed lane if the row-lock race test is skipped.
