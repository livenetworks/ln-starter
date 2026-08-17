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
| Token leakage through URL/history | Token-bearing GET immediately redirects to a token-free confirmation URL |
| Token leakage through logs | Route-template logging and mandatory redaction of raw request paths |
| Login flooding / shared NAT | Layered limits by keyed email hash, IP, and originating session rather than IP alone |
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
4. Email link opens `GET /auth/magic/{token}`.
5. Server hashes the token, resolves a pending non-expired attempt, stores a
   short-lived confirmation reference in that browser's session, and redirects
   with 303 to `/auth/magic/confirm`.
6. Confirmation page contains no token in its URL and posts with CSRF protection.
7. `POST /auth/magic/confirm` rechecks eligibility and atomically consumes the
   attempt.
8. Server authenticates the current browser with Laravel session auth,
   regenerates the session, and redirects to the configured home route.

Invalid link GET requests redirect to the same generic invalid/expired page.
They do not reveal whether the attempt was consumed, code-locked, revoked, or
absent.

## Code flow

1. The requesting session stores only the public attempt ID and a random
   requester nonce; the nonce is also stored as a hash on the attempt.
2. User submits the six-digit code to `POST /auth/magic/code` with CSRF
   protection.
3. Server resolves the attempt from session state, verifies the requester nonce,
   applies rate limits, and verifies the keyed code HMAC.
4. Each invalid code atomically increments `code_attempts`.
5. The fifth invalid code sets `code_locked_at`; the email link remains usable.
6. A valid code atomically consumes the attempt and authenticates the originating
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

Existing route names are retained where their meaning remains compatible:

| Method | URI | Name | Purpose |
|---|---|---|---|
| GET | `/login` | `login` | Login form |
| POST | `/auth/magic-link` | `login.magic-link` | Create attempt and queue generic email response |
| GET | `/auth/magic/{token}` | `auth.magic.show` | Establish link confirmation context, then 303 redirect |
| GET | `/auth/magic/confirm` | `auth.magic.confirm` | Token-free confirmation page |
| POST | `/auth/magic/confirm` | `auth.magic.consume` | Atomically consume link proof and authenticate this browser |
| POST | `/auth/magic/code` | `auth.magic.code` | Atomically consume code proof for originating session |
| POST | `/logout` | `logout` | CSRF-protected session logout |

The v1 `/magic/wait` and `/magic/status` routes are removed.

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

## Rate limits

Defaults are configurable, with these initial limits:

- Attempt creation: 5 per keyed normalized email per 15 minutes.
- Attempt creation: 20 per IP per 15 minutes.
- Attempt creation: 5 per originating session per 15 minutes.
- Code verification: 5 total failures per attempt.
- Code verification: 10 submissions per IP per 15 minutes.
- Link confirmation: 10 submissions per IP per 15 minutes.

Rate-limit keys contain HMACs, never raw email addresses or tokens. Shared IPs
are not blocked after only a handful of distinct users.

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
- `auth.logout.succeeded`

Events may contain attempt ID, actor ID after resolution, request ID, route,
method, outcome, and duration. They must not contain raw email, link token, code,
session ID, cookie, Authorization header, or full token-bearing URL.

## Consequences

### Positive

- Removes login approval transfer between devices.
- Removes polling and its race conditions.
- Uses framework-native session and CSRF protections.
- Makes database disclosure insufficient to recover active proof values.
- Produces a small, testable state machine with explicit terminal states.

### Trade-offs

- Desktop users must type a code when email is read on another device.
- The package needs a new migration and a compatibility/migration policy.
- Six-digit codes require strict attempt limits and a protected HMAC pepper.
- Availability still depends on email delivery; break-glass access is a separate
  operational decision and should prefer passkeys/recovery codes over a lone
  privileged password.

## Implementation sequence

1. Build request correlation, structured security logging, and redaction.
2. Add the attempt model, migration, eligibility contract, and proof hasher.
3. Implement the transactional state machine service.
4. Replace controller routes and mail/view templates.
5. Remove polling/session token issuance and the old cleanup behavior.
6. Run the acceptance matrix in `docs/auth-v2-test-matrix.md` across supported
   Laravel versions.
