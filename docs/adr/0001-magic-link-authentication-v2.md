# ADR 0001: Magic-link authentication v2

- Status: Accepted for implementation
- Date: 2026-08-17
- Scope: LN-Starter built-in authentication module
- Replaces: cross-device approval and polling flow

## Context

The current authentication flow creates a login request in one browser and lets
an email link opened on another device mark that request as approved. The
requesting browser then polls the server and receives a Sanctum token.

That design allows login-request substitution: an attacker can start a request
for a victim's email address, and an unaware victim can approve the attacker's
browser by clicking the email confirmation. Polling also introduces a race with
cleanup, concurrent token issuance, and additional session state.

The v2 design must support both common usage patterns:

1. The user requests and opens the email on the same device.
2. The user requests on a desktop but reads email on a phone.

## Decision

Every authentication email contains two independent proofs for one login
attempt:

- A high-entropy link that authenticates only the browser that opens and
  confirms it.
- A six-digit code that authenticates only the browser session that created the
  login attempt.

Consuming either proof atomically consumes the whole attempt and invalidates the
other proof. There is no cross-device approval and no polling endpoint.

The two usage patterns are alternatives, not a promise to authenticate both
devices from one attempt. The email presents them as two explicit choices:

- **Sign in on this device** opens the link confirmation flow and warns that
  confirming it invalidates the desktop code.
- **Sign in on the requesting device** tells the user to leave the link
  unconfirmed and type the displayed code in the originating browser.

Merely opening the link never invalidates the code. If the user explicitly
confirms the phone, the phone becomes the chosen authenticated device and the
desktop must start a new attempt. This avoids silently minting two sessions
from one email.

First-party browser applications use Laravel's session authentication. A
successful login calls `Auth::login($user)` and regenerates the session. The
flow does not return a personal access token and does not create a custom bearer
token cookie. Native or third-party API token exchange is a separate, explicit
future capability.

## Security invariants

These conditions must always hold:

1. An attempt is consumed at most once, including under concurrent requests.
2. Opening a link with GET never authenticates or consumes an attempt.
3. Link confirmation authenticates the browser that submitted the confirmation.
4. Code confirmation authenticates only the session that created the attempt.
5. A link never approves a different browser session.
6. Link tokens and codes are never stored in plaintext.
7. Link tokens, codes, cookies, session identifiers, and Authorization headers
   are never written to logs.
8. Unknown, ineligible, and eligible email addresses receive the same public
   request response.
9. Disabled or otherwise ineligible accounts cannot create a usable proof or
   authenticate.
10. Every state-changing browser request remains CSRF-protected.
11. Successful authentication regenerates the session identifier.
12. Expired, revoked, or consumed attempts cannot be reused; a code-locked
    attempt may still be consumed through its valid email link.
13. The link and code from one attempt cannot be combined with another attempt.
14. A successful authentication revokes all other pending attempts for the same
    user after the winning attempt is consumed.
15. If the account's canonical email changes after attempt creation, the old
    attempt cannot authenticate the account.
16. Every link confirmation context is session-bound, expires independently,
    is single-use, and identifies exactly one attempt; concurrent contexts in
    one browser cannot overwrite or select each other.
17. A valid code is checked before the failed-attempt counter is incremented;
    a correct code after four failures is accepted.

## Threat model

### Addressed threats

| Threat | Control |
|---|---|
| Attacker initiates login for victim | Email link logs in only the victim's browser; attacker still lacks the code |
| Email security scanner follows GET link | GET only establishes confirmation context; POST performs consumption |
| Database read or backup disclosure | Link token is SHA-256 hashed; code uses keyed HMAC with a dedicated pepper |
| Six-digit code brute force | Five attempts per login attempt plus IP/email/session rate limits and atomic code-only lockout |
| Concurrent replay | Transaction, row lock, and conditional pending-to-consumed transition |
| Account enumeration | Identical status, body, redirect, and queued-response behavior for all emails |
| Session fixation | Session regeneration after successful login |
| Token propagation after initial email navigation | One-time short-lived token; immediate 303 to token-free context; `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, and redacted edge/application route logging |
| Token leakage through logs | Route-template logging and mandatory redaction of raw request paths |
| Login flooding / shared NAT | Layered limits by attempt, keyed email hash, originating session, and a higher IP ceiling rather than IP alone |
| Disabled account login | Configurable eligibility contract checked at request time and again at consumption |
| Mail delivery retry duplicates | One attempt may be delivered more than once, but remains single-use |

### Explicitly out of scope

- A fully compromised mailbox.
- Malware controlling the authenticating device.
- A user intentionally disclosing the six-digit code to an attacker.
- Recovery when both email and configured break-glass mechanisms are unavailable.

These risks require MFA/passkeys, device security, user education, and an
operational recovery policy outside this flow.

## State machine

Allowed persisted states:

- `pending`: link or code may still be verified.
- `consumed`: authentication succeeded via exactly one method.
- `revoked`: invalidated after another attempt for the same user succeeded or by
  an administrative action.
- `expired`: optional materialized cleanup state; expiry is always enforced from
  `expires_at`, even before cleanup runs.

Allowed transitions:

```text
pending -> consumed   valid link confirmation or valid originating-session code
pending -> pending    maximum code attempts reached; code_locked_at is set
pending -> revoked    another attempt succeeds or an administrator revokes it
pending -> expired    expires_at passes / cleanup materializes expiry
```

`consumed`, `revoked`, and `expired` are terminal and immutable. A pending
attempt with `code_locked_at` rejects further code submissions but retains its
independent link proof.

## Link flow

1. Browser submits an email to `POST /auth/magic-link` with CSRF protection.
2. Server always returns the same public response.
3. For an eligible account, server creates a pending attempt and queues one
   email containing the link and code.
4. Email link opens `GET /auth/magic/{token}`, whose token parameter is
   constrained to the package's base64url token format.
5. Server hashes the token, resolves a pending non-expired attempt, stores a
   confirmation context under a random public context ID in that browser's
   session, and redirects with 303 to `/auth/magic/confirm/{context}`. The token
   response sends `Referrer-Policy: no-referrer` and `Cache-Control: no-store`.
6. Confirmation page contains no authentication proof in its URL, clearly
   labels the action as **Sign in on this device**, warns that confirmation
   invalidates the code, and posts with CSRF protection.
7. `POST /auth/magic/confirm/{context}` atomically pulls the matching session
   context, rechecks eligibility, and consumes the attempt.
8. Server authenticates the current browser with Laravel session auth,
   regenerates the session, and redirects to the configured home route.

The context ID is correlation data, not an authentication proof: it is usable
only with the browser session that received it. Session state is a map keyed by
context ID, not a single "current attempt" slot. Each entry contains only the
attempt ID and an absolute expiry of `min(now + 5 minutes, attempt expiry)`.
Entries are removed with a session `pull` under that lock on the first
CSRF-valid POST. Expired entries are rejected, and a session retains at most
three contexts, evicting the oldest when a fourth is created. Route-level
session blocking is required for confirmation POSTs so concurrent requests
cannot reuse the same context. Production configuration must provide the
lock-capable backend required by Laravel session blocking and fails readiness
validation if it cannot. Database row locking remains the final protection
against duplicate attempt consumption.

Invalid link GET requests redirect to the same generic invalid/expired page.
They do not reveal whether the attempt was consumed, code-locked, revoked, or
absent.

The raw proof necessarily exists in the original email link and first HTTP
request; no design can honestly guarantee that every browser omits that initial
navigation from local history. The contract prevents the token from propagating
to the confirmation URL, query strings, Referer headers, rendered HTML, and
application logs, and limits residual exposure through short expiry and
single-use consumption.

## Code flow

1. The requesting session stores only the public attempt ID and a random
   requester nonce; the nonce is also stored as a hash on the attempt.
2. User submits the six-digit code to `POST /auth/magic/code` with CSRF
   protection.
3. Server resolves the attempt from session state, verifies the requester nonce,
   applies rate limits, and verifies the keyed code HMAC.
4. Under the attempt row lock, server first rejects an already locked attempt,
   then performs the constant-time code comparison.
5. A valid code consumes the attempt without incrementing `code_attempts`, even
   when four earlier failures exist.
6. Only an invalid HMAC comparison atomically increments `code_attempts`; when
   the new value is five it also sets `code_locked_at`. Requests rejected before
   proof comparison by binding or rate limits do not alter the failure counter.
7. A valid code atomically consumes the attempt and authenticates the originating
   browser session.

The endpoint never accepts an attempt ID supplied only by request input. It must
match server-side session context.

## Storage contract

The replacement table is `magic_login_attempts`:

| Column | Purpose |
|---|---|
| `id` UUID/ULID | Public correlation identifier; not an authentication secret |
| `user_id` foreign key | Eligible account being authenticated |
| `email_key` char(64) | Keyed normalized-email hash for safe rate limiting |
| `pepper_id` string | Non-secret key-ring identifier used for `email_key` and `code_hash` |
| `link_token_hash` char(64), unique | SHA-256 hash of at least 32 random bytes |
| `code_hash` char(64) | HMAC-SHA-256 of attempt ID and zero-padded six-digit code |
| `requester_nonce_hash` char(64) | SHA-256 hash binding code use to originating session |
| `status` string | `pending`, `consumed`, `revoked`, or `expired` |
| `code_attempts` unsigned tiny integer | Failed code submissions, default zero |
| `consumed_via` nullable string | `link` or `code` |
| `expires_at` timestamp | Absolute attempt expiry |
| `consumed_at` nullable timestamp | Successful authentication time |
| `code_locked_at` nullable timestamp | Time after which code verification is disabled |
| `revoked_at` nullable timestamp | Revocation time |
| timestamps | Creation and maintenance timestamps |

The model must not expose secret hashes through array/JSON serialization.

The dedicated HMAC pepper is configured separately from database content. It is
never stored in the table and must fail closed when missing in production. Code
comparison uses `hash_equals`.

Purpose-separated values are derived from the pepper, for example
`HMAC(pepper, "email\0" + canonical_email)` and
`HMAC(pepper, "code\0" + attempt_id + "\0" + code)`. Reusing a digest in a
different context is not allowed.

Pepper configuration is a versioned key ring, not a single replace-in-place
secret. Each attempt records the non-secret `pepper_id`; new attempts use the
configured current key, while consumption resolves the recorded key. Rotation
is performed by adding the new key, deploying the expanded key ring, switching
the current ID, and retaining prior keys until no unexpired attempt references
them (at least attempt lifetime plus clock-skew allowance). Removal is blocked
while a pending attempt references that ID. A missing current key or a missing
key referenced by an active attempt fails closed, fails readiness/config
validation, and emits a redacted `auth.magic.pepper.unavailable` operational
event. Key material and HMAC values are never logged. Rotating the current key
may reset keyed rate-limit buckets, so the IP and session layers remain active
during rotation.

Email input is trimmed, length-limited, validated, and canonicalized consistently
for account lookup and rate limiting. Domain handling may use IDNA conversion;
the package must not apply provider-specific transformations such as removing
dots or plus-address tags. Consumption verifies that the user's current
canonical email still matches the attempt's `email_key`.

## Eligibility contract

The package must not hard-code an `is_active` column. Applications provide an
implementation equivalent to:

```php
interface AuthEligibility
{
    public function allows(Authenticatable $user): bool;
}
```

The package default allows existing users, while applications may reject
inactive, suspended, archived, tenant-disabled, or role-restricted accounts.
Eligibility is checked both before email delivery and immediately before
authentication.

## Route contract

Static confirmation/code routes are registered before the wildcard token route.
The wildcard additionally uses
`->where('token', '[A-Za-z0-9_-]{43,128}')`, matching the generated base64url
format. Link-confirmation route names are intentionally new because retaining a
v1 name whose `{token}` parameter disappeared would let old calls append the
secret as a query string.

| Method | URI | Name | Purpose |
|---|---|---|---|
| GET | `/login` | `login` | Login form |
| POST | `/auth/magic-link` | `login.magic-link` | Create attempt and queue generic email response |
| POST | `/auth/magic/code` | `auth.magic.code` | Atomically consume code proof for originating session |
| GET | `/auth/magic/confirm/{context}` | `auth.magic.link.confirm` | Token-free, session-bound confirmation page |
| POST | `/auth/magic/confirm/{context}` | `auth.magic.link.consume` | Consume context and link proof; authenticate this browser |
| GET | `/auth/magic/{token}` | `auth.magic.link.open` | Establish link confirmation context, then 303 redirect |
| POST | `/logout` | `logout` | CSRF-protected session logout |

Callers must not pass a link token to either confirmation route. The only route
that accepts the raw token is `auth.magic.link.open`, where it is a required
path parameter and never a query parameter.

The active v2 flow removes v1 `/magic/wait` and `/magic/status`; temporary
compatibility tombstones for published v1 views are defined in the rollout
policy below.

## Public response contract

Attempt creation always returns HTTP 202 for JSON clients and the same generic
browser confirmation page:

```json
{
  "message": {
    "type": "info",
    "title": "Check your email",
    "body": "If an eligible account exists, a sign-in link and code have been sent.",
    "data": []
  },
  "content": null
}
```

Invalid proof submission returns a generic authentication failure. Detailed
reasons exist only in redacted security events and metrics.

### Request timing normalization

The HTTP request handler never performs an eligible-only insert or mail send.
After syntax validation and layered rate-limit checks, every non-rate-limited
request dispatches the same `ShouldQueue` + `ShouldBeEncrypted` request job and
returns the generic response. The encrypted worker job performs account lookup,
eligibility evaluation, proof generation, attempt creation, and mail delivery;
for unknown/ineligible accounts it performs the same canonicalization and
purpose-separated HMAC work but creates no usable attempt and sends no mail.

The controller applies a configurable minimum response floor plus small random
jitter to both successful and queue-dispatch-failure paths. Rate limits bound
the number of timing samples. Automated tests assert identical response and job
contracts and that no eligibility-dependent work occurs synchronously; a
separate statistical timing smoke test compares distributions with a generous
non-flaky budget. This is defense in depth, not a claim of cycle-identical
execution.

## Rate limits

Defaults are configurable, with these initial limits:

- Attempt creation: 5 per keyed normalized email per 15 minutes.
- Attempt creation: 20 per IP per 15 minutes.
- Attempt creation: 5 per originating session per 15 minutes.
- Code verification: 5 total failures per attempt.
- Code verification: 10 submissions per keyed email and per originating session
  per 15 minutes, plus 50 per IP per 15 minutes.
- Link GET: 10 submissions per link-token hash and 100 per IP per 15 minutes.
- Link confirmation POST: 5 submissions per confirmation context and session,
  plus 50 per IP per 15 minutes.

Rate-limit keys contain HMACs, never raw email addresses or tokens. Shared IPs
are a coarse abuse ceiling and are not blocked after only a handful of failures
from distinct users. The per-attempt proof counter is independent of transient
rate-limit buckets and is authoritative for code lockout.

## Expiry and cleanup

- Default attempt lifetime: 15 minutes.
- Expiry is checked during every verification and consumption query.
- Cleanup never participates in correctness and may run at any time.
- Cleanup deletes terminal or expired rows only after a configurable retention
  window, default 24 hours.
- Cleanup never deletes a fresh pending or freshly consumed attempt.

## Required observability events

The observability step must define these stable events before auth v2 is coded:

- `auth.magic.request.accepted`
- `auth.magic.request.rate_limited`
- `auth.magic.delivery.queued`
- `auth.magic.delivery.failed`
- `auth.magic.link.opened`
- `auth.magic.proof.rejected`
- `auth.magic.code.locked`
- `auth.magic.attempt.expired`
- `auth.magic.login.succeeded`
- `auth.magic.login.failed`
- `auth.magic.pepper.unavailable`
- `auth.logout.succeeded`

Events may contain attempt ID, actor ID after resolution, request ID, route,
method, outcome, and duration. They must not contain raw email, link token, code,
session ID, cookie, Authorization header, or full token-bearing URL.

## Legacy middleware and token policy

Auth v2 itself does not use `AuthorizationFromCookie`, `cookie.auth`,
`AuthenticateWithSanctum`, `sanctum.token`, personal access tokens, or the
`auth_token` cookie. Its routes use the framework web guard and session only;
its logout path therefore has no `currentAccessToken()` branch.

`sanctum.token` remains a supported, opt-in package facility for applications
that expose explicit bearer-token APIs. `cookie.auth` is marked deprecated: it
remains registered for one compatibility cycle for consumer-owned legacy
flows, but is removed from the auth setup instructions and is never added by
the v2 installer. The personal-access-token migration remains publishable for
those API consumers but is no longer an auth-v2 prerequisite. The v2 change set
must update `README.md`, `docs/auth.md`, `docs/middleware.md`, `CLAUDE.md`, the
package skill, installer output, and logout tests together so no document asks
new applications to enable the cookie bridge.

## Compatibility and migration policy

Auth v2 is a breaking auth-flow cutover and does not attempt to convert active
v1 proofs into v2 proofs.

1. The v2 migration creates `magic_login_attempts` alongside the old
   `magic_link_tokens` table. Deployment is additive and rollback-safe.
2. Before v2 routes become active, deployment revokes/deletes every pending v1
   token and clears `magic_link_user_id` / `magic_link_token_id` from sessions.
   Users with an in-flight v1 email receive the generic invalid response and
   must request a new login.
3. `ln-starter:auth-v2-audit` detects published auth overrides, especially
   `resources/views/vendor/ln-starter/auth/magic_wait.blade.php`, and exits
   non-zero until the operator republishes or manually ports the v2 login,
   email, and confirmation/code views. `ln-starter:install` runs this audit and
   prints exact affected paths; it never silently overwrites consumer views.
4. For one deprecation release, `GET /magic/wait` redirects to the v2 login page
   with the generic message, and read-only `GET|POST /magic/status` tombstones
   return JSON `{"ok":false,"error":"No session","upgrade_required":true}`
   with HTTP 410. POST retains normal CSRF protection. Supporting both methods
   covers original published views and the later CSRF-hardened v1 view. This
   exact payload makes either polling script stop instead of retrying a 404
   loop. The tombstones never create credentials or inspect an account. They
   are removed in the next breaking release.
5. Old route helpers `auth.magic.show` and `auth.magic.consume` are not aliased:
   aliases could append a legacy token as a query parameter. The upgrade guide
   requires mail/view overrides to use `auth.magic.link.open`,
   `auth.magic.link.confirm`, and `auth.magic.link.consume`.
6. After the rollback window and retention period, a separate explicit cleanup
   migration may drop `magic_link_tokens`; the initial v2 migration never does.

Deployment order is therefore: ship additive schema and audit command, update
published views/config, invalidate v1 pending state, activate v2 routes, observe
the compatibility tombstones, then remove legacy storage only in a later
release.

## Consequences

### Positive

- Removes login approval transfer between devices.
- Removes polling and its race conditions.
- Uses framework-native session and CSRF protections.
- Makes database disclosure insufficient to recover active proof values.
- Produces a small, testable state machine with explicit terminal states.

### Trade-offs

- Desktop users must type a code when email is read on another device; choosing
  "Sign in on this device" intentionally signs in the phone and invalidates the
  desktop code.
- The package needs an additive migration, published-view audit, compatibility
  tombstones, and an explicit v1 invalidation cutover.
- Six-digit codes require strict attempt limits and a protected HMAC pepper.
- Availability still depends on email delivery; break-glass access is a separate
  operational decision and should prefer passkeys/recovery codes over a lone
  privileged password.

## Implementation sequence

1. Build request correlation, structured security logging, and redaction.
2. Add the attempt model, additive migration, eligibility contract, versioned
   pepper key ring, proof hasher, and configuration/readiness validation.
3. Implement the transactional state machine service, layered rate limits,
   encrypted generic request job, timing envelope, and bounded confirmation
   context map.
4. Register static routes before the constrained link-token wildcard; replace
   controller routes and mail/view templates using the new route names.
5. Add the published-view audit, v1 state invalidation, and temporary
   `/magic/wait` + `/magic/status` compatibility tombstones.
6. Remove polling, PAT/cookie issuance, cookie-bridge setup, and PAT-specific
   logout behavior from built-in auth while retaining generic bearer middleware
   as documented above.
7. Update every auth/middleware/install document and the package skill, then run
   the acceptance matrix in `docs/auth-v2-test-matrix.md` across supported
   Laravel versions and a row-locking database.
