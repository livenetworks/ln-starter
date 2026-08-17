# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
- Production support matrix and release contract (ADR 0003): Laravel 12/13 supported, Laravel 11 compatibility-only, MySQL/InnoDB and PostgreSQL supported, SQLite refused in production
- `docs/deployment.md` with a deploy checklist, queue-worker runbook, monitoring signals, pepper rotation and a rollback procedure
- Fresh-consumer install and upgrade harnesses (`scripts/consumer-install.php`, `scripts/consumer-upgrade.php`) driving package discovery, publishing, installer idempotency, migrations, config/route/view caching, readiness and HTTP smoke tests in a throwaway Laravel application
- `composer verify-artifact`: builds the distributable archive and asserts its contents, absence of `vendor/`/`.env`/tests/CI/agent settings, manifest validity and installability
- Production deployment readiness: https `APP_URL`, secure/http-only/same-site session cookie, no apex cookie domain, configured queue connection and mailer — all config-only, no network or mail
- Fail-closed test-database guard: dropping all tables requires `APP_ENV=testing`, an explicit opt-in variable and an allow-listed database name, and refuses production-like names outright
- Cache-compatibility tests: serializable config, no closure-action routes, `{token}` stays a placeholder in the cached route table, no query during package boot, and a simulated Octane scope reset
- Structured security event pipeline: versioned envelope, closed severity/outcome/reason-code vocabularies, and a public `SecurityEventLogger` API for package and application events (ADR 0002)
- `SecurityAuditSink` contract plus `SecurityEventDispatcher::extend()`, so applications add destinations without modifying package code
- Recursive allow-list `ContextSanitizer` with deny-list veto, depth/field/value bounds, invalid-UTF-8 handling, and no `__toString()` on arbitrary objects
- Versioned, purpose-separated pseudonymous identifiers (`v1:<digest>`) with rotation that keeps previous versions resolvable
- `ln.request-id` middleware: validated inbound correlation header, generated ULID otherwise, echoed in the response, and inherited by the queued magic-link job
- Real monotonic `duration_ms` on auth requests, proof verification, session creation, logout, and mail delivery
- Opt-in `ln_security_audit_events` database sink with a publishable migration and `SecurityAuditEvent` read model
- `ln-starter:security-audit-prune` with `--dry-run`, chunked portable deletes, and a production `--force` gate
- Security-logging readiness checks folded into `ln-starter:auth-v2-readiness`
- `docs/security-logging.md` with the event catalog, sink extension, pepper rotation, retention, and SIEM guidance

### Changed
- Auth v2 events renamed to the canonical catalog (`auth.magic.proof.accepted`, `auth.session.created`, `auth.session.terminated`, …); consumers matching the previous ad-hoc names must update
- Magic-login state transitions stage their events and emit only after the locking transaction commits, so a rollback cannot produce a false success and exactly one winner reports acceptance under concurrency
- Rate-limit events now distinguish email, IP, and session throttles through internal reason codes; the public response is unchanged

### Security
- Keep CSRF protection enabled for session and cookie-authenticated requests; only `disable-csrf:bearer` routes carrying an explicit Authorization bearer token are excluded
- Delegate `sanctum.token` authentication to Sanctum's official guard so token expiry, provider checks, events, and usage tracking are preserved
- Protect modal submissions, auth proof consumption, and the built-in logout route with normal CSRF validation
- Invalidate the authenticated web session and rotate its CSRF token during logout
- Deprecate the legacy `auth_token` cookie bridge; built-in auth v2 neither issues nor authenticates from it
- Publish an additive first/last-name migration without replacing consumer-owned users migrations
- Replace polling/PAT magic login with a single-use, row-locked link-plus-code state machine that authenticates Laravel web sessions
- Store only purpose-separated proof digests, bind codes to the requesting session, and support versioned HMAC pepper rotation
- Process email eligibility and delivery in encrypted queue jobs behind enumeration-resistant public responses and layered throttles
- Emit structured allow-listed security events without email, token, code, cookie, authorization, body, or session secrets
- Fail auth-v2 production readiness when the database cannot provide transactional row locking (SQLite, or a non-InnoDB `magic_login_attempts` table), since `lockForUpdate()` is silently a no-op there and single-use consumption would not be atomic

### Fixed
- Audit the losing side of a concurrent proof consumption: a terminal attempt now stages `auth.magic.proof.replayed` with `proof_already_consumed`/`proof_revoked`, and a missing attempt stages `attempt_not_found`. Previously a race left only a success event, indistinguishable from an uncontested login
- Resolve the scoped `RequestContext` per call in `SecurityEventLogger` as well as the dispatcher; the singleton logger captured one instance and could hand a stale correlation ID to the queue under Octane or a long-running worker
- Name per-proof and per-confirmation-context throttles `rate_limited_proof` and `rate_limited_confirmation_context` instead of mislabelling them as session limits
- Report a throttled magic-link open as `auth.magic.rate_limited`; it was previously reported as `auth.magic.proof.replayed` with `proof_expired`, putting a false reuse claim in the audit trail for a valid pending link
- Emit reason-specific rate-limit events for code verification and link confirmation; only the initial email request was instrumented
- Probe the storage engine on the connection the audit table actually lives on; a sink using a separate connection could pass readiness against the default database
- Guard `SecurityAuditSink::name()` as well as `write()`; a sink throwing from `name()` escaped into the auth flow and defeated the fail-open guarantee
- Pseudonymize any principal reference that is not already a versioned digest, and drop `principal_key`/`user_id` from the context allow-list, so the public API cannot publish a raw identifier
- Bind `RequestContext` as scoped and resolve it per event, so Octane requests and successive queue jobs cannot inherit each other's correlation ID
- Persist `environment`, `application`, and `guard` in the database sink; a shared audit store could not attribute a row to its origin
- Emit `security.readiness.failed` with `pepper_unavailable` instead of the undocumented `auth.magic.pepper.unavailable`, matching UPGRADE.md
- Assert exactly one `auth.magic.proof.accepted` in the real forked race test through a shared database sink, rather than only counting in-process winners
- Isolate the test database between test classes on server-backed connections; without it, tables created by one class leaked into the next and the MySQL lane failed on table-ordering rather than on behaviour
- Pin the MySQL test connection to InnoDB instead of the server default, so the row-lock race contract is never exercised on a non-transactional engine
- Run the concurrency race test on PostgreSQL as well as MySQL/MariaDB, rather than skipping it on every non-MySQL driver
- Separate the CI security policy from the compatibility policy: Laravel 12 and 13 are supported production lanes and must pass `composer audit`; Laravel 11 is an end-of-life compatibility lane that is exempt from the audit gate. A green Laravel 11 lane proves only that the package still installs and runs there — it is not evidence that Laravel 11 is security-supported, and the package does not advertise it as a production target. The EOL lane sets `COMPOSER_NO_SECURITY_BLOCKING=1` for dependency resolution; that variable is not recognised by Composer 2.8.10 (verified against the shipped phar), so it is currently inert and is kept only until the Composer version GitHub Actions actually provisions has been confirmed

### Changed
- Add `<x-ln.logout-form />` as the CSRF-safe package logout control
- Retain `/magic/wait` and `GET|POST /magic/status` only as one-release HTTP 410 tombstones that cannot issue credentials
- Implement the accepted magic-link v2 link-plus-code state machine and its security acceptance suite
- Tighten the auth-v2 specification with constrained route ordering, bounded confirmation contexts, explicit cross-device UX, layered rate limits, versioned pepper rotation, and a v1 published-view migration policy
- Add a Laravel 11/12/13 CI matrix that runs on SQLite, MySQL, and PostgreSQL — the stack's documented primary database
- Exclude Laravel 11 × PHP 8.5 from CI: 11.x reached end of life before PHP 8.5 shipped and will never receive a compatibility fix, so the lane would be permanently red without testing anything
- Exclude `.claude/`, `.gitignore` and `.gitmodules` from the release archive
- Add auth-v2 upgrade audit/readiness/cutover commands, published-view preflight, retention-aware cleanup, and a separate optional Sanctum migration tag
- Auth views redesigned: card-based layout with gradient backgrounds, inline SVG icons, animations, and richer UX (info boxes, countdown, troubleshooting tips)
- Auth SCSS (`auth.scss`) rewritten as fully standalone — no ln-acme dependency; uses CSS custom properties and self-contained BEM classes
- Auth layout (`_auth.blade.php`) simplified to minimal HTML shell; views handle their own full-screen layout

## [0.1.0] — 2026-03-14

### Added
- `LNController` with dual-mode response (`respondWith`)
- `LNReadModel` (read-only Eloquent for DB views)
- `LNWriteModel` (write Eloquent, no timestamps)
- `Message` DTO for unified response messages
- `BusinessException` for domain-level errors
- Middleware: `AuthenticateWithSanctum`, `AuthorizationFromCookie`, `DisableCsrf`, `VerifyCsrfToken`
- Blade layouts: `_ln` (layout switcher), `_ajax` (JSON response)
- Config file with publishable assets
- Stubs for scaffolding controllers and models
- Documentation (README, CLAUDE.md, docs/)
