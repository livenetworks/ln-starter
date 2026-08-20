# Monitoring baseline and operational runbooks

Operational companion to [`security-logging.md`](security-logging.md), which
defines the events themselves. This document says what to watch and what to do.

Every event name, field and reason code used here exists in the code — see
`SecurityEventName`, `ReasonCode` and `SecurityEvent::toArray()`. Nothing below
invents a signal.

## Monitoring baseline

**Thresholds are deliberately relative.** An absolute number that is right for
one application is wrong for the next, and a threshold nobody calibrated is a
threshold nobody trusts. Establish a baseline over a normal week, then alert on
deviation. The windows below are starting points, not prescriptions.

| Signal | Source event | Group by | Window | Threshold philosophy | False positives | Operator action |
|---|---|---|---|---|---|---|
| Abnormal rate-limit volume | `auth.magic.rate_limited` | `reason_code`, `principal_key` | 5–15 min | Multiple of the weekly baseline for that reason code | Marketing sends, a retry-happy client, a shared corporate NAT | Identify whether one principal or many; if many, check for a distributed attempt. Do not lower the limits reflexively |
| Replay detection | `auth.magic.proof.replayed` | `principal_key`, `reason_code` | 15 min | Any sustained rise above baseline; a low steady rate is normal | Double-click or double-submit on the confirmation form. **Not** link prefetch — the link `GET` is read-only and consumes nothing, so a scanner opening it cannot produce a replay | If `proof_already_consumed` dominates, likely benign duplication. `proof_revoked` in volume warrants investigation |
| Expired / invalid proof spike | `auth.magic.proof.expired`, `auth.magic.proof.rejected` | `reason_code` | 15 min | Deviation from baseline | Delayed mail delivery pushes users past the window | Correlate with `auth.magic.delivery.*` latency before blaming the user |
| Delivery failure | `auth.magic.delivery.failed` | `reason_code` | 5 min | **Near-zero tolerance** — any sustained rate means logins are failing | A single transient transport blip | Treat as an outage. See "Delivery outage" below |
| Session creation failure | `auth.session.created` with `outcome != success` | `reason_code` | 15 min | Any non-trivial rate | — | Usually session-driver or database trouble, not auth logic |
| Readiness failure | `security.readiness.failed` | `reason_code` | immediate | **Any occurrence** | — | Configuration drift. `pepper_unavailable` is the common case |
| Pepper unavailable | `security.readiness.failed` + `reason_code=pepper_unavailable` | — | immediate | **Any occurrence** | — | A pepper was rotated or dropped. See "Pepper rotation" |
| Audit sink failure | `security.audit.sink_failed` | `sink` | 5 min | **Any occurrence** | — | Auth is still up — the pipeline is degraded, not the login. See "Audit sink outage" |
| Queue / mail outage | `auth.magic.delivery.queued` without a matching `delivery.succeeded`/`failed` | `correlation_id` | 10 min | Queued-without-terminal above baseline | Worker restart during deploy | Check worker health first; a stopped worker produces exactly this shape |
| Principal concentration | `auth.magic.request.received` | `principal_key` | 1 h | One key at a large multiple of the median | Shared device, load test | `principal_key` is pseudonymous — you cannot read the email from it. Correlate through your own application logs |
| IP-driven throttling | `auth.magic.rate_limited` with `reason_code=rate_limited_ip` | `reason_code` | 15 min | Deviation from baseline | Shared corporate NAT | **There is no IP in the envelope or the audit table**, by design. This reason code tells you an IP limit fired; identifying *which* address requires your web server or edge logs |

### State-machine rejection routing

This row is the authoritative mapping for reasons sent through the state
machine's `reject()` path. Keep it aligned with the code; these reasons do not
belong to the replay event.

| State-machine path | Event | Reason codes |
|---|---|---|
| `reject()` | `auth.magic.proof.rejected` | `code_locked`, `requester_binding_mismatch`, `invalid_code`, `ineligible_principal`, `email_changed` |

### Query notes

The database sink table is `ln_security_audit_events`. Real columns only:
`event_name`, `occurred_at`, `severity`, `outcome`, `reason_code`,
`request_id`, `correlation_id`, `principal_key`, `attempt_id`, `route`,
`http_method`, `status_code`, `auth_method`, `guard`, `duration_ms`,
`context`, `environment`, `application`.

```sql
-- Portable ANSI. Replace the interval expression for your engine:
-- PostgreSQL: now() - interval '15 minutes'
-- MySQL:      now() - interval 15 minute
SELECT reason_code, count(*) AS hits
FROM ln_security_audit_events
WHERE event_name = 'auth.magic.rate_limited'
  AND occurred_at >= ?          -- pass the window start as a bound parameter
GROUP BY reason_code
ORDER BY hits DESC;
```

Indexed for this shape: `occurred_at`, `(event_name, occurred_at)`,
`correlation_id`, `request_id`, `outcome`, `principal_key`, `attempt_id`,
`(application, environment)`. A query that filters on `context` is not indexed
and will scan.

**There is no email, IP, token or code in this table** — do not write a query
that expects one. `principal_key` is a versioned HMAC digest, and grouping by it
is the intended way to count per-principal activity.

Retention prunes this table (default 90 days), so a dashboard with a longer
window silently loses its earlier half. Match the two.

## Runbook: authentication incident

### Evidence collection, without secrets

Pivot on `correlation_id`. It is assigned by `ln.request-id`, survives into the
queued mail job, and appears on both log lines and audit rows — one attempt
reconstructs across HTTP and queue.

```sql
SELECT occurred_at, event_name, outcome, reason_code, duration_ms
FROM ln_security_audit_events
WHERE correlation_id = ?
ORDER BY occurred_at;
```

`attempt_id` narrows to one magic-login attempt. Never attach raw request
bodies, headers or `.env` fragments to an incident ticket: the pipeline
deliberately excludes them, and pasting them back in defeats that.

### Replay spike

1. Group `auth.magic.proof.replayed` by `reason_code`.
2. `proof_already_consumed` dominating, with matching `auth.session.created`
   successes → duplicate submissions. Benign.
3. `proof_revoked` in volume → proofs are being superseded or invalidated
   faster than users complete them. Contain as below.

`requester_binding_mismatch` is a **different event**: it is emitted under
`auth.magic.proof.rejected`, not `proof.replayed`, because a proof presented
from a session it was not bound to was never consumed. Watch it there. In
volume it means someone is presenting proofs from another session, and is the
more serious of the two signals.

### Brute-force / code attempts

Watch `auth.magic.code.locked` and `auth.magic.proof.rejected` with
`invalid_code`. The code lock is automatic. If one `principal_key` dominates,
the account is being targeted; if many keys each show a few attempts, it is
spraying.

**Containment.** The package's own limits are **not runtime-configurable** in
2.0.0 — they are fixed in `AuthController` (creation: 5 per email, 20 per IP,
5 per session, in a 900-second window; verification limits alongside them).
There is no config key to turn, so containment happens at the edge:

- rate-limit or challenge the auth routes at your WAF, CDN or load balancer;
- block or tarpit the offending source there;
- if the target is one account, disable that account through your own
  application's controls.

Do not disable auth v2 to stop an attack: the v1 endpoints are inert tombstones
and offer no fallback, so switching it off removes login entirely.

Making these limits configurable is a reasonable feature request against a
future minor version. It is not something to change quietly during an incident.

### Delivery outage

1. `auth.magic.delivery.failed` with `mail_transport_failure` → the transport.
2. `delivery.queued` with no terminal event → the **worker**, not the mailer.
3. Check worker health, then transport credentials, then the provider.

Users cannot log in during a delivery outage and there is no bypass. That is
intentional. Communicate rather than improvising a back door.

### Suspicious session creation

`auth.session.created` from an unexpected `route`, or in unusual volume for one
`principal_key`. Correlate with the preceding `proof.accepted` on the same
`attempt_id` — a session without one is the serious case and should be
impossible.

### Recovery and post-incident verification

Re-run `ln-starter:auth-v2-readiness`, perform a real login end to end, and
confirm the expected event sequence appears with a single `correlation_id`.

## Runbook: pepper rotation

`ln-starter.auth.peppers` is versioned: `current` names the key that signs new
proofs, `keys` maps every id that must remain resolvable.

1. Add the new key alongside the old one. **Do not remove the old key.**
2. Point `current` at the new id.
3. Rebuild the config cache and **restart queue workers.** A stale worker does
   not silently fall back to the old pepper: the queued job carries an explicit
   `pepperId`, so a worker whose config lacks that key fails while hashing
   rather than producing a proof nobody can verify. The failure is loud, but it
   is still a failed login — restart the workers.
4. Keep the retired key for at least as long as a pending attempt can live —
   the attempt expiry window. Nothing longer is required: the auth pepper signs
   proofs only.

   **The auth pepper is not the audit pepper.** `principal_key` digests come
   from `ln-starter.logging.pseudonym`, a separate key with a separate rotation
   procedure below. Rotating the auth pepper does not affect audit correlation,
   and rotating the pseudonym key does not affect authentication.
5. Verify with `ln-starter:auth-v2-readiness`.

**Removing a key too early** makes every pending attempt that references it
unverifiable, and readiness refuses to boot. That failure is loud on purpose —
it is preferable to silently rejecting real users.

Rollback: put the old key back and restore `current`. Proofs signed with the
new pepper during the window remain verifiable as long as the new key also
stays in `keys`.

## Runbook: pseudonym key rotation

`ln-starter.logging.pseudonym` follows the same versioned shape, but the
consequence is different and worth stating plainly:

**Rotating the pseudonym key changes every future `principal_key`.** The same
user produces a different digest before and after. Historical correlation
breaks at the rotation boundary — dashboards that group by `principal_key`
across it will double-count one person as two.

Therefore:

- Rotate only for a reason (suspected key exposure, policy), not on a routine.
- Keep the retired key for at least the audit retention window, so old rows
  remain attributable.
- Record the rotation timestamp where your analysts will see it.
- Expect and accept the discontinuity; do not attempt to rewrite old rows.

Unlike the auth pepper, this rotation does **not** break authentication. Nobody
is locked out. Only the audit trail's continuity is affected.

## Runbook: audit sink outage

**Authentication keeps working.** Sinks are fail-open by design: a sink that
throws is caught, reported as `security.audit.sink_failed` with
`reason_code=sink_failure`, and the login proceeds. Auditing never takes down a
login.

The corollary is that **events emitted while a sink is down are lost** for that
sink. There is no queue and no replay. If the log sink is still healthy, the
event is still on disk there; if the failing sink was the only one, that window
is gone.

1. `security.audit.sink_failed` grouped by `sink` names the failing
   destination.
2. Database sink: check the connection named by `LN_SECURITY_AUDIT_CONNECTION`,
   that `ln_security_audit_events` exists, and that migrations ran.
3. Log sink: check the channel and its fallback.
4. Recovery is confirmed by a real login producing a row or line, not by the
   absence of further failures — an unused sink also produces no failures.

**When this is a production blocker:** if your compliance position depends on a
complete audit trail, a sink outage is an incident in its own right even though
users are unaffected. Decide that in advance rather than during.

## Runbook: retention

```bash
php artisan ln-starter:security-audit-prune --dry-run          # always first
php artisan ln-starter:security-audit-prune --force            # required in production
php artisan ln-starter:security-audit-prune --days=30 --chunk=500
```

Scheduling (`routes/console.php`, or your scheduler of choice):

```php
Schedule::command('ln-starter:security-audit-prune --force')->dailyAt('03:15');
```

- The window comes from `LN_SECURITY_AUDIT_RETENTION_DAYS` (default 90) unless
  `--days` overrides it.
- Deletes are chunked and portable; a large first run is normal.
- `--force` is required in production. That guard is deliberate: pruning is
  irreversible.
- **Choosing the window is a legal and organisational decision, not a technical
  one.** The default is a starting point, not advice.

Verify pruning actually runs: compare `min(occurred_at)` against the window.

```sql
SELECT min(occurred_at) AS oldest, count(*) AS rows_kept
FROM ln_security_audit_events;
```

If `oldest` drifts past the retention window, the schedule is not running.
Monitor table growth as well — an unpruned audit table is the most likely
source of unexpected database growth in an application using this package.
