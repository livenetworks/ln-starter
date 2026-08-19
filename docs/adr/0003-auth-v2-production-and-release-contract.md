# ADR 0003: Auth v2 production and release contract

- Status: Accepted for implementation
- Date: 2026-08-18
- Scope: Support matrix, install/upgrade contract, release qualification
- Builds on: [ADR 0001](0001-magic-link-authentication-v2.md), [ADR 0002](0002-security-audit-logging-and-observability.md)

## Context

ADR 0001 specified the auth flow and ADR 0002 the audit pipeline. Both are
implemented and covered by a suite that runs inside Testbench.

Testbench is not a consumer application. It never exercises package discovery,
`vendor:publish`, the installer command, `config:cache`, `route:cache`, or an
upgrade over a previous installation. A package can have a fully green suite and
still be uninstallable, destroy a consumer's migrations on upgrade, or ship
`vendor/` inside its own release archive.

This ADR defines what has to be true — and demonstrated — before a release.

It adds no new auth behaviour.

## Support matrix

| Lane | Status | Notes |
|---|---|---|
| Laravel 13 | **Supported** | Requires PHP ^8.3 |
| Laravel 12 | **Supported** | Requires PHP ^8.2; package floor is ^8.3 |
| Laravel 11 | **Compatibility only** | End of life. Not a security-supported target |
| PHP 8.3 / 8.4 / 8.5 | Supported | Subject to the framework lane's own constraint |
| MySQL / MariaDB (InnoDB) | **Supported** | Row locking required |
| PostgreSQL | **Supported** | The stack's documented primary database |
| SQLite | Development and unit tests only | **Refused in production** |

Laravel 11 exists in CI so the package keeps installing for consumers who have
not upgraded. A green Laravel 11 lane proves installability and nothing more; it
is not evidence that Laravel 11 is safe to run, and the package must never be
advertised as supporting it in production. Supported lanes are additionally
gated on `composer audit`; the EOL lane is exempt, which is precisely why its
green status carries no security meaning.

Laravel 11 × PHP 8.5 is excluded from CI: 11.x reached end of life before PHP
8.5 shipped and will never receive a compatibility fix for it, so the lane would
be permanently red without telling us anything about the package.

SQLite is refused in production because `lockForUpdate()` is silently a no-op
there. The single-use consumption invariant from ADR 0001 depends on real row
locking, and a database that cannot provide it would break that invariant with
no error at all.

## Fresh-install contract

A consumer running `composer require livenetworks/ln-starter` then
`php artisan ln-starter:install` must get a working installation with:

- automatic package discovery;
- publishable config, migrations, views, stubs and skill;
- an installer that is **idempotent** — running it twice changes nothing;
- an installer that is **non-destructive** — it never overwrites or deletes a
  file the consumer owns;
- a working `migrate`, `config:cache`, `route:cache`, `view:cache`;
- `ln-starter:auth-v2-readiness` passing.

The optional audit table is not created unless the sink is explicitly enabled
**and** its migration published.

## Upgrade contract

Upgrading from a previous installation must preserve consumer property:

- consumer-owned users migrations are never modified or deleted;
- published views are never replaced without `--force`;
- an old published config keeps working — new nested keys merge recursively
  underneath the consumer's values, which stay authoritative;
- legacy `magic_link_tokens` rows are not destroyed by the upgrade;
- retention cleanup deletes only aged terminal rows, never fresh pending ones;
- the legacy endpoints stay credential-free for one release, exactly as ADR
  0001 section 4 specifies them: `GET /magic/wait` redirects to the v2 login
  page, and `GET|POST /magic/status` is the HTTP 410 tombstone (the POST
  variant still CSRF-protected);
- `ln-starter:auth-v2-audit` reports stale published views and config rather
  than silently proceeding.

### Migration ownership

| Migration | Owner | Publish |
|---|---|---|
| `magic_login_attempts` | Package | Loaded automatically when auth is enabled |
| `ln_security_audit_events` | Consumer | Opt-in, publish + migrate |
| `personal_access_tokens` | Consumer | Optional, only for consumer-owned bearer APIs |
| additive first/last name | Consumer | Published by the installer, additive only |
| `users` | **Consumer** | Never written by the package |

The package never edits a migration it does not own. Where the previous
behaviour replaced or deleted files, the installer now refuses and explains.

## Cache and runtime contract

- No closure or non-serializable value in published config.
- No closure-action route.
- `config:cache`, `route:cache`, `view:cache` all succeed.
- The cached route table keeps `{token}` as a placeholder — never a value.
- Package boot performs **no** database query. Cheap config checks run per
  boot; anything needing a connection runs only in the readiness command.
- `RequestContext` is a scoped binding, and singletons resolve it per call, so
  Octane requests and successive queue jobs never inherit stale correlation
  state or a stale locale default.

## Deployment requirements

Validated by readiness, refusing to boot in production otherwise:

**Transport** — `APP_URL` must be `https`. The magic link inherits it, so this
single setting decides whether the proof travels in the clear. Behind a
TLS-terminating proxy, TrustProxies must be configured.

**Session** — the session cookie *is* the credential after login: `secure`,
`http_only`, `same_site` of `lax` or `strict`, and no apex cookie domain.

**Queue and mail** — a non-`sync` queue with a configured connection, and a
configured mailer. Readiness never sends mail and never opens a network
connection.

**Database** — transactional row locking; InnoDB for the attempt and audit
tables on MySQL/MariaDB, with an unreadable engine treated as a failure.

**Observability** — resolvable log and fallback channels, a valid pseudonym key
and version, and, when the audit sink is enabled, an existing table on a
reachable connection.

Readiness output never contains key material or credentials.

## Release artifact

The published archive must contain the runtime package and nothing else:
no `vendor/`, `.git/`, `.env`, tests, CI workflows, build scripts, logs, local
databases, editor or agent settings. It must carry a valid `composer.json` with
PSR-4 autoloading and Laravel discovery, and must be installable **from the
artifact**, not only from the working tree.

This is enforced by `composer verify-artifact`, which builds the archive and
inspects it rather than trusting `archive.exclude` to be correct.

## Rollback policy

Auth v2 replaced the v1 polling flow, so rollback is a real operational
question rather than a `composer downgrade`.

- The `magic_login_attempts` table is additive; leaving it in place is harmless.
- The audit table is opt-in and independent; it can be dropped separately.
- Pending attempts do not survive a rollback and users simply request a new
  link — acceptable because attempts are short-lived by design.
- Rolling back **after** a pepper rotation without restoring the previous key
  makes existing attempts unverifiable. Keep retired keys for at least the
  retention window.
- Downgrading below the release that removed PAT issuance does not restore
  tokens that were never minted.

The full procedure lives in [`docs/deployment.md`](../deployment.md).

## Deprecation timeline

| Item | Deprecated | Removal | Note |
|---|---|---|---|
| `AuthorizationFromCookie` / `cookie.auth` | now | next **major** | Unused by auth v2; still available for consumer-owned bearer APIs |
| `/magic/wait`, `/magic/status` tombstones | now | next **minor** | Credential-free: `/magic/wait` redirects to login, `/magic/status` is HTTP 410 |
| `database/migrations/auth/create_magic_link_tokens_table.php` | now | next **major** | No longer loaded or published |
| Pre-catalog event names | now | already replaced | Mapping in `UPGRADE.md` |
| Auth v1 published views | now | next **minor** | Reported by `ln-starter:auth-v2-audit` |

Deprecated public API is not removed in a patch or minor release when doing so
would break a consumer application.

Never reintroduced: personal access token issuance in the built-in flow, the
`auth_token` cookie, a state-changing GET, or any weakening of CSRF.

## What `production-ready` means

All of the following must be **demonstrated**, not asserted:

1. Supported-lane suites green on SQLite, MySQL and PostgreSQL.
2. The forked row-lock race test **executed** — not skipped — on MySQL and
   PostgreSQL, proving one winner, one `proof.accepted`, and at least one
   recorded loser.
3. `composer audit` clean on Laravel 12 and 13.
4. Fresh-install harness green on Laravel 12 and 13.
5. Upgrade harness green on Laravel 12 and 13.
6. Artifact verification green.
7. Readiness passing on a valid production config and failing on each unsafe one.
8. Documentation consistent with code and CI.

Anything less is **conditionally production-ready** at best. A branch that has
not been pushed, or whose CI has not run, cannot be production-ready regardless
of how green the local suite is — the local machine is not a supported lane.

## Automatic versus operational

**Automatic** (CI must enforce): the eight gates above, plus installer
idempotency, migration non-destructiveness, cache compatibility, and the
test-database reset guard.

**Operational** (deployment responsibility, documented not enforced): TLS
termination and proxy trust, queue worker supervision and restart-on-deploy,
failed-job handling, log shipping and retention, audit table growth, pepper
custody and rotation schedule, and database backups before migration.

The package validates configuration. It cannot validate that someone is running
the queue worker.
