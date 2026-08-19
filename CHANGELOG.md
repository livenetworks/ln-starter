# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0] — unreleased (release candidate)

> **BREAKING.** Every installation upgrading from 1.x needs configuration
> changes before it will boot: `auth.peppers.current` and `auth.peppers.keys`
> are now required, and the service provider throws without them. See
> [docs/releases/2.0.0.md](docs/releases/2.0.0.md) for the full inventory and
> the upgrade procedure, and [UPGRADE.md](UPGRADE.md) for the step-by-step.
>
> Not tagged. The date is written when the tag is created.

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
- Add `<x-ln.logout-form />` as the CSRF-safe package logout control
- Retain `/magic/wait` and `GET|POST /magic/status` for one release as inert compatibility endpoints that cannot issue credentials: `/magic/wait` redirects to the v2 login page, `/magic/status` returns HTTP 410 with the documented payload, and its POST variant keeps normal CSRF protection
- Implement the accepted magic-link v2 link-plus-code state machine and its security acceptance suite
- Tighten the auth-v2 specification with constrained route ordering, bounded confirmation contexts, explicit cross-device UX, layered rate limits, versioned pepper rotation, and a v1 published-view migration policy
- Add a Laravel 11/12/13 CI matrix that runs on SQLite, MySQL, and PostgreSQL — the stack's documented primary database
- Exclude Laravel 11 × PHP 8.5 from CI: 11.x reached end of life before PHP 8.5 shipped and will never receive a compatibility fix, so the lane would be permanently red without testing anything
- Exclude `.claude/`, `.gitignore` and `.gitmodules` from the release archive
- Add auth-v2 upgrade audit/readiness/cutover commands, published-view preflight, retention-aware cleanup, and a separate optional Sanctum migration tag
- Auth views redesigned: card-based layout with gradient backgrounds, inline SVG icons, animations, and richer UX (info boxes, countdown, troubleshooting tips)
- Auth SCSS (`auth.scss`) rewritten as fully standalone — no ln-acme dependency; uses CSS custom properties and self-contained BEM classes
- Auth layout (`_auth.blade.php`) simplified to minimal HTML shell; views handle their own full-screen layout

### Removed

**Breaking.** Each of these is in the public API defined by [ADR 0004](docs/adr/0004-versioning-release-and-distribution.md).

- Route names `auth.magic.show` and `auth.magic.consume`; a `route()` call for either now throws
- `AuthController::magicShow()`, `magicConsume()`, `magicStatus()` and `magicWait()`
- Published views `magic_wait.blade.php` and `magic_success.blade.php` removed. Both are detected by name by `ln-starter:auth-v2-audit`, and `ln-starter:install` refuses to run while either is still published: `magic_wait` polls an endpoint that is now a tombstone, and `magic_success` is orphaned because auth v2 has no success page. Neither can be ported — both are deletions
- `database/migrations/auth/create_magic_link_tokens_table.php` from every publish tag — the file remains in the tree but is no longer loaded or published
- The Sanctum personal-access-token migration from `ln-starter-migrations`; it now has its own `ln-starter-sanctum-migrations` tag
- Polling and personal-access-token issuance from the built-in login flow

### Deprecated

- The `cookie.auth` middleware and the `auth_token` cookie bridge. Auth v2 neither issues nor reads it. Still available for consumer-owned bearer APIs; removed in 3.0.0
- `/magic/wait` and `GET|POST /magic/status`, kept inert for one release; removed in 3.0.0

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
- Close the remaining gap in `--require-final`. Dropping the words "release candidate" was enough to pass, even though ADR 0004 requires a date, and `UPGRADE.md` was not checked at all — so a release could ship with a candidate banner and an `Unreleased` heading covering changes it contains. Finalisation now demands a dated `# LN-Starter X.Y.Z — YYYY-MM-DD` heading in the release notes, a dated `## X.Y.Z — YYYY-MM-DD` heading in `UPGRADE.md` with no candidate marker and no leftover `Unreleased` section, and one real calendar date agreed across all three documents
- Fold the security-logging and production-requirements sections of `UPGRADE.md` into 2.0.0. They were headed `Unreleased` while describing changes this release ships
- Make release finalisation provable **before** the tag exists. The preflight checked for candidate wording only once a tag was present, which is unrecoverable: the tag would already be on a commit the preflight refuses, and the policy forbids moving it, so the version number would have to be burned. `--require-final` brings the same demand forward to the commit about to be tagged, requires a dated changelog heading, and the release workflow passes it on the rehearsal path too, so a dry run exercises the path a tag will take
- Detect `magic_success.blade.php` by name in the upgrade audit. It was documented as blocking the installer but could not be: the shipped v1 copy references no removed route and no legacy field, so the content scan never saw it and a published copy would have sat in the consumer tree indefinitely. Both removed views are now matched by name, and the documentation says to delete rather than port them, since auth v2 has neither page
- Stop presenting `LN_SECURITY_PSEUDONYM_KEY` as a boot requirement in `UPGRADE.md`. Only `LN_AUTH_PEPPER` blocks boot; the pseudonym key has an `APP_KEY`-derived fallback. The pepper requirement is also stated correctly now — it must be set before `composer require`, not merely before the first artisan call, because package discovery boots the provider during the require itself
- Build the release archive exactly once. The release workflow ran `release-check.php` (which builds, inspects and checksums archive A) and then `verify-artifact.php` again, uploading archive B — so the qualified and published artifacts were different files, which is precisely what ADR 0004 forbids. The preflight now exports the archive it qualified, re-checksums the exported bytes, and the workflow only uploads them
- Make a release depend on the full qualification. `release.yml` defined its own reduced matrix and `publish` did not wait for the `Tests` workflow, so a GitHub Release could be created while the full run was red or still going. It now calls `tests.yml` as a reusable workflow — 24 lanes, artifact job and both consumer jobs — and runs the upgrade harness, not just a fresh install, against the canonical archive
- Stop interpolating the tag and workflow input into shell. Git permits a tag such as `v1.0.0$(id)`, and `${{ }}` pasted it into a `run:` block before any validation. Both values now reach the shell through `env` and are validated against the canonical form where they are resolved and again where they are published
- Correct the documented upgrade order, which could not be executed: it called `ln-starter:auth-v2-audit` before `composer require`, but that command does not exist in 1.2.1, and it omitted `ln-starter:install` entirely. The published order is now the one the upgrade harness actually runs
- Require an annotated tag in the release preflight, and refuse to publish while the release notes still describe themselves as a candidate
- Honour the application's own Vite configuration when emitting the auth assets. The first repair inspected `public/hot` and `public/build/manifest.json` by hand, so an application using `useHotFile()`, `useBuildDirectory()` or `useManifestFilename()` had correct, built assets and silently got none of them. Rendering now goes through the configured `Illuminate\Foundation\Vite` service and catches only `ViteException` — the two "not built" cases — so every other failure still propagates
- Assert both halves of the auth-v2 cutover refusal in the upgrade harness. The result of the no-`--force` call was discarded, so a command that wrongly exited 0 would have passed as long as it happened to change nothing; the exit code and the `--force` explanation are now asserted alongside the existing data-unchanged checks
- Fail the suite if a script the CI workflow drives stops shipping. The workflow is parsed, its script invocations extracted, and each one checked to exist and be tracked — a deleted harness would otherwise remove a gate silently and surface only as "No such file or directory"
- Correct the documented legacy-endpoint contract to match the accepted ADR 0001: `/magic/wait` redirects to the v2 login page and only `/magic/status` is an HTTP 410 tombstone. ADR 0003, `docs/auth.md`, this changelog and the upgrade harness had all drifted to claiming 410 for both, so that harness step asserted something the implementation never did
- Render the shipped auth pages without a frontend build. `layouts/_auth.blade.php` called `@vite()` unconditionally, which throws when the manifest is missing or does not list an entry — so a fresh consumer, or any deploy where the asset build was skipped, got HTTP 500 on `/login` rather than an unstyled working page. The suite could not see it: every auth test overrode `ln-starter.auth.layout` with a fixture, so the layout that ships had never been rendered. The tags are now emitted only when the application has actually built them, and `AuthPageRenderingTest` renders the real layout
- Let the release artifact's installability probe evaluate the shipped config. `config/ln-starter.php` calls `env()`; `illuminate/support` provides that helper but does not require `vlucas/phpdotenv` (`laravel/framework` does), so the probe modelled an environment no consumer has and failed on a missing `PhpOption`. The probe now installs the Laravel baseline; the package's own requirements are unchanged
- Assert the upgrade audit and the installer *fail closed* on a stale published v1 view instead of running them through a helper that throws on any non-zero exit. Both commands report by exiting non-zero, so those harness steps could never have passed; the harness now asserts the refusal and then models the documented remediation
- Replace a `catch (RuntimeException) {}` around the cutover refusal, which would equally have swallowed a harness bug, with an explicit failure-tolerant call
- Report the response that failed a consumer smoke test — status plus document title, with key-shaped values redacted, since the throwaway application runs with `APP_DEBUG=true` and the text reaches a public CI annotation
- Emit harness failures as GitHub workflow annotations: the run summary otherwise shows only "Process completed with exit code 1", and reading the step log requires repository admin rights
- Actually write the artifact path handed to CI: `--output-path-file` was read but never written, so the consumer job that reads it could only fail. It is now written after every check passes, refuses to run without `--keep-extracted` (which would name a directory about to be deleted), and fails the gate if the write fails. Covered by a regression test that fails when the write is removed
- Add a source-hygiene gate that rejects C0 control characters and invalid UTF-8 in tracked files, and parses the CI workflow. Two escape sequences had been written literally as bytes 0x01 and 0x02 — invisible to `php -l`, PHPUnit, and `git diff --check`, but enough to break a regex backreference and a workflow step
- Hand the extracted artifact path to CI through a file instead of parsing it out of human-readable output
- Refuse an empty or unknown database host in the test-reset guard instead of treating it as loopback, and stop counting `host.docker.internal` as local
- Restore both test-reset opt-in variables in `tearDown` rather than clearing them, so a lane where either is legitimately set is not broken by this test class
- Limit the illustrative-APP_KEY exemption in the artifact secret scanner to documentation; a weak-looking key in config or source is still reported
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


## [1.2.1] — 2026-06-02

### Fixed
- Forget the `{locale}` route parameter after consuming it

## [1.2.0] — 2026-04-07

### Added
- Auto-publish the Claude skill on package install

## [1.1.1] — 2026-03-31

### Fixed
- Show the success page on POST instead of redirecting

## [1.1.0] — 2026-03-31

### Changed
- Magic-link token consumption is POST-only

## [1.0.0] — 2026-03-30

First tagged release. Tagged `1.0.0`, without the `v` prefix used by every later
tag; left as published rather than retagged (see
[ADR 0004](docs/adr/0004-versioning-release-and-distribution.md)).

### Added
- `LNController` with dual-mode response (`respondWith`)
- `LNReadModel` (read-only Eloquent for database views)
- `LNWriteModel` (write Eloquent, no timestamps)
- `Message` DTO for unified response messages
- `BusinessException` for domain-level errors
- Middleware `AuthenticateWithSanctum` (with a `:required` guard), `AuthorizationFromCookie`, `DisableCsrf`, `VerifyCsrfToken`, and `SetLocale` for URL-prefix multilanguage
- Blade layouts `_ln` (layout switcher) and `_ajax` (JSON response)
- `<x-ln.toast />` and `<x-ln.modal />` components, aligned with ln-acme HTML conventions
- Passwordless magic-link authentication with token management and a users-table migration
- `ln-starter:install` with Vite entry injection, plus controller/model/user stubs
- Publishable config, views, layouts, migrations, stubs and the Claude skill
- Documentation (README, CLAUDE.md, docs/)

### Changed
- Require PHP 8.3 and support Illuminate 13.x
- Auth views redesigned with standalone CSS, dropping the ln-acme dependency
- Register core middleware aliases directly in the service provider

### Fixed
- Skip CSRF for token-authenticated users regardless of middleware order
- Read the magic-link token by route name rather than position
- Set `URL::defaults` so `route()` injects the locale parameter automatically
- Auto-redirect to login when a magic-link token has expired

---

Sections below 1.0.0 describe pre-release development and were never tagged.
The repository's first commit is dated 2026-03-14; a previously published
`[0.1.0] — 2026-03-14` section in this file did not correspond to any tag and
has been folded into 1.0.0 above, which is the first release that exists.
