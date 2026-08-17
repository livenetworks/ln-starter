# Auth v2 acceptance test matrix

This matrix is the executable specification for the magic-link v2
implementation. Each row becomes an automated feature or concurrency test.

## Attempt creation

| ID | Scenario | Expected result |
|---|---|---|
| A01 | Eligible email requests login | 202/generic response; one pending attempt and one queued email |
| A02 | Unknown email requests login | Same status, body, redirect, and public timing class; no usable attempt |
| A03 | Ineligible email requests login | Same public response; no usable proof; redacted rejection event |
| A04 | Same IP requests for several distinct users within normal limit | Requests are accepted; shared NAT is not blocked by five total requests |
| A05 | Email-specific limit exceeded | Generic response; no additional attempt/email; rate-limit event |
| A06 | Mail queue dispatch fails | Generic public response; exception reported with redacted context |

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
| L08 | Link token visible in redirected URL or response | Test fails; redirected location and rendered page contain no token |

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

## Cleanup

| ID | Scenario | Expected result |
|---|---|---|
| P01 | Fresh pending attempt | Never deleted |
| P02 | Freshly consumed attempt | Retained until retention window passes |
| P03 | Expired attempt inside retention window | Unusable but retained |
| P04 | Terminal/expired attempt beyond retention window | Deleted by cleanup |
| P05 | Cleanup is rerun | Idempotent result with no error |

## Compatibility

The matrix must run against:

- Laravel 11 / Testbench 9
- Laravel 12 / Testbench 10
- Laravel 13 / Testbench 11
- The package's supported PHP versions for each framework combination

Tests that exercise concurrency must use a database capable of row locking;
SQLite-only success is not sufficient for the atomic-consumption contract.
