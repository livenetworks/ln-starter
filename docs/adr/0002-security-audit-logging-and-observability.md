# ADR 0002: Security audit logging and auth observability

- Status: Accepted for implementation
- Date: 2026-08-17
- Scope: LN-Starter security event pipeline, auth v2 instrumentation
- Builds on: [ADR 0001](0001-magic-link-authentication-v2.md)

## Context

Auth v2 ([ADR 0001](0001-magic-link-authentication-v2.md)) records a handful of
events through `SecurityEventLogger`, an allow-list wrapper around Laravel's
logger. That was enough to prove the auth flow does not leak secrets, but it is
not an observability foundation:

- there is exactly one sink (the framework log) and no way for a consuming
  application to add its own without editing package code;
- `duration_ms` is allow-listed but never populated, so latency is invisible;
- correlation IDs are generated per request but do not survive into queued jobs,
  so a magic-link delivery cannot be tied back to the request that caused it;
- the event envelope is implicit — there is no schema version, severity, or
  outcome vocabulary, so downstream consumers must pattern-match on strings;
- the allow-list operates on a flat array, so a nested structure passed by a
  consumer would be forwarded verbatim;
- there is no durable audit trail, only log lines.

This package is the foundation for downstream applications. Their audit
requirements will be stricter than the package's own, so the pipeline has to be
extensible from the outside.

## Goals

1. One canonical, versioned event envelope for every security event.
2. A public, stable API that consuming applications can emit events through.
3. A sink contract so applications add destinations without forking the package.
4. Sanitization that fails safe on arbitrary, hostile, or malformed input.
5. Correlation that survives the HTTP boundary and the queue boundary.
6. Real latency measurement on the operations that matter.
7. An optional durable audit trail with retention controls.
8. Readiness validation that catches misconfiguration before traffic does.

## Non-goals

- **Not a general-purpose application logger.** Business/domain logging stays in
  the application. This pipeline is for security-relevant events only.
- **Not a metrics/tracing system.** `duration_ms` is recorded per event; the
  package does not ship histograms, spans, or an exporter.
- **Not a compliance product.** The package provides mechanisms. It does not
  make an application GDPR, ISO 27001, or SOC 2 compliant, and it does not
  claim to.
- **Not guaranteed delivery.** See [Failure semantics](#failure-semantics).
- **No PII vault.** The package never stores raw email, IP, or session values,
  so it also offers no way to reverse a pseudonymous key.

## Threat model

### Addressed

| Threat | Control |
|---|---|
| Credential capture from log files | Allow-list sanitizer; secrets are never envelope fields |
| PII accumulation in logs/SIEM | Raw email/IP/session never stored; only versioned HMAC keys |
| Log injection via user-controlled input | Structured context only; no string interpolation of payloads |
| Correlation-ID header injection | Strict format + length validation; invalid input is replaced, never echoed |
| Audit tampering via unbounded context | Depth, field-count, and value-length caps |
| Denial of service through log volume | Context caps and truncation; sinks are failure-isolated |
| Log-driven crash of the auth flow | Sink failures are caught; auth never 500s because auditing failed |
| Recursive failure storms | Sink-failure reporting is depth-guarded and cannot re-enter |
| Enumeration through log-derived behaviour | Reason codes are internal only; public responses stay generic |
| Pseudonym correlation after key compromise | Versioned peppers with rotation and an overlap window |
| Silent audit loss | Fallback channel + `security.audit.sink_failed` + readiness checks |

### Explicitly out of scope

- An attacker with read access to the log store already sees pseudonymous
  activity graphs. Pseudonymization limits identity disclosure, not activity
  correlation.
- An attacker who holds the active pepper can confirm a guessed email maps to a
  given key. Peppers must be protected like application keys.
- Log storage integrity, WORM, and shipping are the deployment's responsibility.
- A compromised application process can log whatever it likes.

## Event schema

Every event is an immutable `SecurityEvent` serialized to a flat envelope.

| Field | Type | Notes |
|---|---|---|
| `event_id` | ULID | Unique per emission; not a correlation key |
| `event_name` | string | Dotted, from the [event catalog](../security-logging.md#event-catalog) |
| `schema_version` | int | Currently `1`; incremented on breaking envelope change |
| `occurred_at` | RFC3339 UTC, ms precision | Always UTC regardless of app timezone |
| `severity` | enum | `debug`, `info`, `notice`, `warning`, `error`, `critical` |
| `outcome` | enum | `success`, `failure`, `rejected`, `error`, `pending` |
| `reason_code` | enum \| null | Constrained set; never a raw exception message |
| `request_id` | string \| null | Per-request identifier |
| `correlation_id` | string \| null | Survives HTTP → queue; equals `request_id` at origin |
| `environment` | string | `app.env` |
| `application` | string | `app.name` |
| `route` | string \| null | Route **name or template** — never a resolved URI with parameters |
| `http_method` | string \| null | |
| `status_code` | int \| null | When known at emission time |
| `auth_method` | string \| null | `magic_link`, `magic_code`, `session`, `none` |
| `guard` | string \| null | When relevant |
| `principal_key` | string \| null | Pseudonymous; `v1:<64 hex>` |
| `attempt_id` | ULID \| null | Magic-login attempt; already a non-secret public correlator |
| `duration_ms` | float \| null | Monotonic; non-negative |
| `context` | object | Sanitized; may be empty |

`schema_version` is part of the contract. Adding an optional field is a
non-breaking change and does not bump it. Removing a field, renaming one, or
changing a type does.

Framework objects (`Request`, `Response`, models, exceptions) are never placed
in the envelope or the context.

## Privacy rules

### Never logged, in any field

magic-link token · verification code · bearer/PAT token · `Authorization` header
· any cookie · raw session ID · CSRF token · password · password confirmation ·
request body · raw email · raw IP address · SMTP credentials · `APP_KEY` ·
encryption keys · HMAC peppers · stack traces carrying any of the above.

### Pseudonymization

Identifiers that must be correlatable but not readable are stored as
`<pepper_version>:<hmac-sha256 hex>`, for example `v1:9f2c…`. Purpose separation
matches ADR 0001: the HMAC input is `"<purpose>\0<canonical value>"`, so the
same email produces different digests for `principal` and for rate limiting.

Rotation keeps the previous version resolvable. During overlap, new events use
the active version while old records remain interpretable. A rotation therefore
never causes a login outage and never invalidates historical audit rows — it
only means keys minted before and after the rotation do not compare equal.

### Sanitizer

Allow-list, not deny-list: an unknown key is dropped rather than inspected. The
sanitizer is recursive and bounded by max depth, max field count, and max value
length. It handles invalid UTF-8, never invokes `__toString()` on arbitrary
objects, and never throws — a context it cannot process degrades to a marker
value rather than failing the event or the request.

## Correlation and request IDs

A middleware assigns the correlation identity.

- An inbound header is honoured **only** if it matches a strict character class
  and length bound. Anything else — missing, malformed, oversized — is replaced
  with a freshly generated ULID.
- The value is stored in request attributes and echoed in the response header.
- The header name is configurable; the default is `X-Request-Id`.
- Queued jobs capture the correlation ID at dispatch and restore it while
  running, so delivery events join the originating request.

Because the value can be attacker-supplied, it is validated before use and is
never interpolated into a log message — only carried as a structured field.

## Log sinks

Sinks implement a single contract and are resolved from the container, so an
application registers its own without touching package code.

1. **Structured log sink** — enabled by default. Writes to a configurable
   channel with the envelope as structured context and severity mapped to PSR-3
   levels. Suitable for shipping to a SIEM.
2. **Database audit sink** — **opt-in**, disabled by default so upgrades and
   fresh installs are unaffected. Writes sanitized rows to
   `ln_security_audit_events` via a publishable migration.

The database sink uses its own table and never shares storage with auth attempt
tables.

## Failure semantics

Auditing is **fail-open with respect to authentication availability**. A sink
that throws must not break a login.

On sink failure the dispatcher:

1. catches the throwable — never lets it escape into the auth flow;
2. writes to the configured fallback channel;
3. emits a minimal `security.audit.sink_failed` event carrying the sink name and
   the throwable **class**, never its message or the original payload;
4. suppresses re-entry, so a failing sink cannot recurse.

Sink work happens outside the state-machine transaction. A sink failure never
triggers a rollback, and a rolled-back transaction never produces a success
event — success is emitted only after the transaction commits.

Delivery guarantees are explicitly **at-most-once, best-effort**. The package
does not claim exactly-once audit delivery: a process kill between commit and
sink write loses the event. Applications needing stronger guarantees should
enable the database sink inside their own transactional outbox.

Readiness checks exist precisely because the runtime policy is fail-open:
misconfiguration must be caught before traffic, not silently absorbed.

## Persistence and retention

`ln_security_audit_events` stores the envelope with indexes on event name,
`occurred_at`, request/correlation IDs, outcome, and principal key.

Retention is enforced by `ln-starter:security-audit-prune`:

- configurable retention window in days;
- `--dry-run` reports matches without deleting;
- chunked deletion, never a bare `DELETE` across the table;
- in production, deletion requires an explicit `--force`;
- rows newer than the cutoff are never touched.

Retention is an operator responsibility; the package ships the mechanism and a
scheduler example, not a default schedule.

## Concurrency semantics

Instrumentation must not perturb the ADR 0001 row-locking contract:

- events are emitted after the locking transaction commits;
- during concurrent consumption exactly one transaction wins, so exactly one
  `auth.magic.proof.accepted` is emitted;
- losers emit `auth.magic.proof.rejected` or `auth.magic.proof.replayed`;
- no sink call happens while a row lock is held.

## Production readiness

Readiness validates configuration shape, that the log channel resolves, that
retention is a positive integer in range, that the active pseudonymization
pepper exists and is not an insecure default, and — when the database sink is
enabled — that the table exists, the connection works, and on MySQL/MariaDB the
audit table is InnoDB. An unknown or null storage engine is a failure, matching
the auth-v2 rule. Readiness output never prints pepper values or credentials.

## Public extension API

```php
use LiveNetworks\LnStarter\Contracts\SecurityAuditSink;
use LiveNetworks\LnStarter\Security\SecurityEvent;

class SiemSink implements SecurityAuditSink
{
    public function name(): string { return 'siem'; }

    public function write(SecurityEvent $event): void
    {
        // $event->toArray() is already sanitized.
    }
}
```

Registered through the dispatcher in a service provider. Applications emit their
own events through the same `SecurityEventLogger` entry point, so package and
application events share one envelope and one sanitizer.

## Consequences

### Positive

- One envelope and one vocabulary across package and applications.
- Applications extend the pipeline without forking.
- Secrets and PII are structurally excluded rather than filtered case by case.
- Correlation spans HTTP and queue boundaries.
- Audit availability problems surface at readiness time.

### Trade-offs

- Allow-listing means a consumer adding a new context field must register it, or
  it is silently dropped. This is deliberate.
- Fail-open auditing means a total sink outage loses events rather than blocking
  logins. Readiness and the fallback channel mitigate; they do not eliminate.
- Pseudonymous keys make incident response harder: an operator cannot map a key
  back to a user without the application performing its own lookup.
- The database sink adds a write per event when enabled, hence opt-in.
