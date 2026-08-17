# Auth v2 acceptance test matrix

This matrix is the executable specification for the magic-link v2
implementation. Each row becomes an automated feature or concurrency test.

## Attempt creation

| ID | Scenario | Expected result |
|---|---|---|
| A01 | Eligible email requests login | 202/generic response; one pending attempt and one queued email |
| A02 | Unknown email requests login | Same status, body, redirect, encrypted queued-job class, and configured response timing envelope; no usable attempt |
| A03 | Ineligible email requests login | Same public response; no usable proof; redacted rejection event |
| A04 | Same IP requests for several distinct users within normal limit | Requests are accepted; shared NAT is not blocked by five total requests |
| A05 | Email-specific limit exceeded | Generic response; no additional attempt/email; rate-limit event |
| A06 | Mail queue dispatch fails | Generic public response; exception reported with redacted context |
| A07 | Compare synchronous work for unknown/ineligible/eligible email | No eligibility-dependent insert or mail operation occurs in the HTTP request; all dispatch the same encrypted job contract |
| A08 | Statistical timing smoke test across account classes | Sample distributions stay within the documented non-flaky budget; rate limits cap sampling |

## Link proof

| ID | Scenario | Expected result |
|---|---|---|
| L01 | Valid link GET | No authentication or consumption; confirmation session created; 303 to token-free URL |
| L02 | Email scanner performs GET only | Attempt remains pending and usable |
| L03 | Valid confirmation POST with CSRF | Attempt consumed via link; current browser session regenerated and authenticated |
| L04 | Confirmation POST without CSRF | Rejected by CSRF middleware; attempt remains pending |
| L05 | Link opened on a different device | Only that device is authenticated after its confirmation POST |
| L06 | Attacker initiated request; victim confirms link | Victim's browser is authenticated; attacker browser remains unauthenticated |
| L07 | Expired/unknown/terminal link | Generic invalid response; no state change and no account disclosure |
| L08 | Link token propagates after initial token-bearing GET | Redirect location, confirmation URL/HTML, Referer behavior, cache headers, and application logs contain no token |
| L09 | Static confirm URL is requested while wildcard link route exists | Static route wins; `confirm` is never parsed as a link token |
| L10 | Two links are opened in the same browser session | Two distinct bounded contexts exist and each resolves only its own attempt |
| L11 | Confirmation context is expired, reused, evicted, or submitted from another session | Generic rejection; no attempt is consumed or authenticated |
| L12 | Two simultaneous POSTs use one confirmation context | Session blocking/context pull allows at most one context use; attempt row remains single-consumption |
| L13 | Phone opens link but does not press "Sign in on this device" | Desktop code remains valid; page explains that confirming the phone invalidates it |
| L14 | Phone explicitly confirms link, then desktop submits code | Phone is authenticated; desktop receives generic terminal failure and must start a new attempt |
| L15 | Legacy `auth.magic.consume` route helper is called with a token | Route is intentionally undefined; no compatibility alias can append the token as a query parameter |

## Code proof

| ID | Scenario | Expected result |
|---|---|---|
| C01 | Correct code from originating session | Attempt consumed via code; originating session regenerated and authenticated |
| C02 | Correct code from another session | Rejected; attempt remains pending |
| C03 | Correct code with wrong requester nonce | Rejected; attempt remains pending |
| C04 | Wrong code attempts one through four | Counter increments atomically; generic failure |
| C05 | Fifth wrong code | Code proof locks; further codes fail, while the independent email link remains usable |
| C06 | Code supplied with request-controlled attempt ID only | Rejected; server-side session context is required |
| C07 | Leading-zero code | Treated as a six-character string and verified correctly |
| C08 | Expired code | Generic rejection; no authentication |
| C09 | Correct code submitted after four invalid comparisons | Correct code succeeds; counter is not incremented to five and attempt is consumed via code |
| C10 | Request is rejected by nonce/session/rate limit before HMAC comparison | `code_attempts` is unchanged |
| C11 | Several users behind one IP each make a small number of mistakes | Per-attempt/session/email limits apply; coarse IP ceiling does not lock normal shared-NAT users |

## Atomicity and replay

| ID | Scenario | Expected result |
|---|---|---|
| R01 | Two simultaneous valid link confirmations | Exactly one succeeds; exactly one authenticated consumption is recorded |
| R02 | Simultaneous valid link and code confirmations | Exactly one method wins; the other receives generic terminal failure |
| R03 | Two simultaneous correct code submissions | Exactly one succeeds; no duplicate login/audit event |
| R04 | Reuse consumed link/code | Rejected without changing terminal state |
| R05 | Successful attempt while other attempts are pending | Winner is consumed; other pending attempts for user become revoked |
| R06 | Cleanup runs during successful consumption | Login correctness is unchanged; fresh row is not deleted |

## Session and token safety

| ID | Scenario | Expected result |
|---|---|---|
| S01 | Successful first-party login | Laravel session is used; no personal access token is created |
| S02 | Successful login | Pre-authentication session ID differs from authenticated session ID |
| S03 | JSON login response | Contains no bearer token, session ID, code, or link token |
| S04 | Auth cookie inspection | Framework session cookie uses configured Secure, HttpOnly, and SameSite policy |
| S05 | Logout without CSRF | Rejected; authenticated session remains active |
| S06 | Logout with CSRF | Session invalidated and CSRF token regenerated |
| S07 | Production session-blocking backend cannot acquire atomic locks | Configuration/readiness fails before auth v2 serves confirmation requests |

## Eligibility and disclosure

| ID | Scenario | Expected result |
|---|---|---|
| E01 | User disabled after email is queued | Consumption fails generically; no authentication |
| E02 | Tenant suspended after email is queued | Consumption fails according to application eligibility policy |
| E03 | Compare unknown/ineligible/eligible request responses | Status, schema, message, and redirect are identical |
| E04 | Invalid proof variants | Public response does not distinguish absent, expired, code-locked, revoked, or consumed |
| E05 | User changes email after attempt creation | Old attempt is rejected and cannot authenticate the account |

## Storage and logging

| ID | Scenario | Expected result |
|---|---|---|
| G01 | Inspect persisted attempt | No plaintext email proof, link token, code, or requester nonce |
| G02 | Inspect model JSON/array | Secret hash columns are hidden |
| G03 | Capture logs for complete flow | No raw email, token, code, cookie, session ID, Authorization header, or token URL |
| G04 | Successful flow | All events share request/attempt correlation where applicable |
| G05 | Missing production HMAC pepper | Application fails closed during boot/config validation |
| G06 | Rotate to a new pepper while old attempts remain pending | New attempts record new ID; old attempts still verify with retained key and no outage occurs |
| G07 | Remove a pepper still referenced by an active attempt | Deployment/readiness fails closed with redacted `auth.magic.pepper.unavailable` event |
| G08 | Inspect queued request payload | Email/request data is encrypted at rest in the queue payload |

## Cleanup

| ID | Scenario | Expected result |
|---|---|---|
| P01 | Fresh pending attempt | Never deleted |
| P02 | Freshly consumed attempt | Retained until retention window passes |
| P03 | Expired attempt inside retention window | Unusable but retained |
| P04 | Terminal/expired attempt beyond retention window | Deleted by cleanup |
| P05 | Cleanup is rerun | Idempotent result with no error |

## Upgrade compatibility

| ID | Scenario | Expected result |
|---|---|---|
| U01 | v2 schema is deployed while v1 table exists | Additive migration succeeds; v1 table is not dropped or rewritten |
| U02 | Cutover occurs with pending v1 proofs/session keys | Old proofs become unusable and old session keys are cleared; user must request a v2 login |
| U03 | Consumer has a published v1 `magic_wait` or auth view | Audit lists exact override and exits non-zero until operator ports or republishes it |
| U04 | Old published wait JavaScript calls `GET` or CSRF-protected `POST /magic/status` | Compatibility tombstone returns HTTP 410 and the exact `No session` JSON, so polling stops without credential issuance |
| U05 | Client calls removed legacy route name with a token | No compatibility alias turns the token into a query parameter; upgrade failure is explicit |
| U06 | Built-in v2 login/logout runs | `cookie.auth`, `sanctum.token`, PAT creation, `auth_token`, and `currentAccessToken()` are not used |
| U07 | Consumer-owned bearer API uses `sanctum.token` | Generic bearer middleware remains supported independently of auth v2 |

## Compatibility

The matrix must run against:

- Laravel 11 / Testbench 9
- Laravel 12 / Testbench 10
- Laravel 13 / Testbench 11
- The package's supported PHP versions for each framework combination

Tests that exercise concurrency must use a database capable of row locking;
SQLite-only success is not sufficient for the atomic-consumption contract.
R01–R06 and L12 are tagged `row-locking`, assert at runtime that the driver is
MySQL/PostgreSQL (otherwise they are explicitly skipped, never reported as a
pass), and run in CI against MySQL for every supported Laravel/Testbench pair.
The remaining suite runs against both SQLite and MySQL. CI is a required part
of the implementation, not a manual release checklist. The initial matrix is
defined in `.github/workflows/tests.yml` and covers PHP 8.3, 8.4, and 8.5 for
each Laravel/Testbench pair on both database drivers.
