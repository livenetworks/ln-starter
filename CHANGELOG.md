# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
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
- Isolate the test database between test classes on server-backed connections; without it, tables created by one class leaked into the next and the MySQL lane failed on table-ordering rather than on behaviour
- Pin the MySQL test connection to InnoDB instead of the server default, so the row-lock race contract is never exercised on a non-transactional engine
- Run the concurrency race test on PostgreSQL as well as MySQL/MariaDB, rather than skipping it on every non-MySQL driver
- Use the real `COMPOSER_NO_AUDIT` variable in CI; the previous `COMPOSER_NO_SECURITY_BLOCKING` is not a Composer setting and had no effect

### Changed
- Add `<x-ln.logout-form />` as the CSRF-safe package logout control
- Retain `/magic/wait` and `GET|POST /magic/status` only as one-release HTTP 410 tombstones that cannot issue credentials
- Implement the accepted magic-link v2 link-plus-code state machine and its security acceptance suite
- Tighten the auth-v2 specification with constrained route ordering, bounded confirmation contexts, explicit cross-device UX, layered rate limits, versioned pepper rotation, and a v1 published-view migration policy
- Add a Laravel 11/12/13 CI matrix that runs on SQLite, MySQL, and PostgreSQL — the stack's documented primary database
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
