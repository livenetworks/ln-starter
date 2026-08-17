# Security audit logging & observability

Structured, privacy-preserving security events for the built-in auth flow and
for your own application code.

Design rationale lives in
[ADR 0002](adr/0002-security-audit-logging-and-observability.md).

> **Compliance note.** This package provides mechanisms — pseudonymization,
> sanitization, retention, readiness checks. It does **not** make an application
> GDPR, ISO 27001, or SOC 2 compliant, and it makes no such claim. Logs produced
> here contain **pseudonymous security identifiers**, which many regimes still
> treat as personal data. Retention, access control, and lawful basis remain
> your responsibility.

## Quick start

Nothing is required: the log sink is on by default and writes through your
application's default channel.

```php
use LiveNetworks\LnStarter\Security\Outcome;
use LiveNetworks\LnStarter\Support\SecurityEventLogger;

app(SecurityEventLogger::class)->event(
    eventName: 'billing.invoice.voided',
    outcome: Outcome::Success,
    context: ['reason' => 'duplicate'],
);
```

Add the correlation middleware to your own routes to get request IDs on
non-package routes (the package's auth routes already have it):

```php
// bootstrap/app.php
$middleware->web(append: [
    \LiveNetworks\LnStarter\Http\Middleware\AssignRequestId::class,
]);
```

## The envelope

Every event is serialized to the same flat structure.

| Field | Notes |
|---|---|
| `event_id` | ULID, unique per emission |
| `event_name` | Dotted name from the catalog below |
| `schema_version` | Currently `1` |
| `occurred_at` | RFC3339, UTC, millisecond precision |
| `severity` | `debug` `info` `notice` `warning` `error` `critical` |
| `outcome` | `success` `failure` `rejected` `error` `pending` |
| `reason_code` | Constrained enum or null |
| `request_id` | Unique per HTTP request or job execution |
| `correlation_id` | Shared across a request and the jobs it dispatches |
| `environment` / `application` | From `app.env` / `app.name` |
| `route` | Route **name or template**, never a resolved URI |
| `http_method`, `status_code` | When known |
| `auth_method` | `magic_link` `magic_code` `session` |
| `guard` | When relevant |
| `principal_key` | Pseudonymous, `v1:<64 hex>` |
| `attempt_id` | Magic-login attempt ULID |
| `duration_ms` | Monotonic, non-negative |
| `context` | Sanitized key/value map |

Both sinks persist the whole envelope, `environment`, `application`, and `guard`
included, so a shared audit store stays attributable to the app that wrote it.

`schema_version` only changes when a field is removed, renamed, or retyped.
Adding an optional field is non-breaking.

## Event catalog

| Event | Severity | Typical outcome |
|---|---|---|
| `auth.magic.request.received` | info | `pending` |
| `auth.magic.request.accepted` | info | `success` |
| `auth.magic.request.rejected` | warning | `rejected` |
| `auth.magic.rate_limited` | warning | `rejected` |
| `auth.magic.delivery.queued` | info | `pending` |
| `auth.magic.delivery.succeeded` | info | `success` |
| `auth.magic.delivery.failed` | warning | `failure` |
| `auth.magic.link.opened` | info | `pending` |
| `auth.magic.proof.accepted` | info | `success` |
| `auth.magic.proof.rejected` | warning | `rejected` |
| `auth.magic.proof.replayed` | warning | `rejected` |
| `auth.magic.proof.expired` | warning | `rejected` |
| `auth.magic.code.locked` | warning | `rejected` |
| `auth.magic.attempts.revoked` | info | `success` |
| `auth.session.created` | info | `success` |
| `auth.session.terminated` | info | `success` |
| `auth.session.terminate_noop` | info | `success` |
| `security.readiness.failed` | error | `error` |
| `security.audit.sink_failed` | error | `error` |

### Reason codes

A closed set, never a raw exception message:

`unknown_principal` `ineligible_principal` `email_changed` `invalid_code`
`invalid_link_token` `requester_binding_mismatch` `proof_expired`
`proof_already_consumed` `proof_revoked` `code_locked` `attempt_not_found`
`confirmation_context_missing` `rate_limited_email` `rate_limited_ip`
`rate_limited_session` `rate_limited_proof` `rate_limited_confirmation_context`
`mail_transport_failure` `delivery_window_expired`
`no_active_session` `sibling_attempt_superseded` `pepper_unavailable`
`configuration_invalid` `sink_failure` `unspecified`

Reason codes are **internal**. They may distinguish "no such account" from
"ineligible account" precisely because the public HTTP response never does.

Throttle reasons name the dimension that actually tripped: `rate_limited_email`,
`rate_limited_ip`, `rate_limited_session`, `rate_limited_proof` (one magic-link
token being hammered), and `rate_limited_confirmation_context`. Every throttle
key is an HMAC or a hash, so the dimension is reported without the identifier.

Throttling is never reported as a replay. A rate-limited open of a valid,
still-pending link emits `auth.magic.rate_limited` and leaves the attempt
untouched.

Under concurrent consumption the winner emits `auth.magic.proof.accepted` and
every loser emits `auth.magic.proof.replayed` with `proof_already_consumed` or
`proof_revoked`. A race that produced only a success event would be
indistinguishable from an uncontested login.

## What is never logged

magic-link token · verification code · bearer/PAT token · `Authorization`
header · cookies · raw session ID · CSRF token · password · password
confirmation · request body · raw email · raw IP · SMTP credentials · `APP_KEY`
· encryption keys · HMAC peppers · stack traces containing any of the above.

Enforced structurally, not by convention:

- **Allow-list first.** A context key that is not registered is dropped, at
  every nesting level, without its value being inspected.
- **Deny-list veto.** Even an allow-listed key is rejected if it matches a
  forbidden name, so an allow-list mistake cannot become a leak.
- **Bounds.** Max depth, max field count, max value length, all configurable.
- **Safety.** Invalid UTF-8 is repaired, non-finite floats are replaced,
  arbitrary objects are described rather than stringified (`__toString()` is
  never called), and the sanitizer never throws.

`principal_key` and `user_id` are **not** allow-listed in context: both are
promoted to validated envelope fields, and permitting them in `context` would
be a second, unvalidated route in. Any value you pass as `principalKey` that is
not already a `<version>:<digest>` pseudonym is pseudonymized for you rather
than trusted — the privacy guarantee holds for application code too.

To emit your own context keys, register them:

```php
'context_allow_list' => ['tenant_id', 'plan'],
```

## Pseudonymous identifiers

Emails, IPs, and session IDs become `<version>:<hmac-sha256>`:

```
v1:9f2c4a…  ← same actor across events, unreadable, non-reversible
```

Purpose separation means the same email yields different digests for
`principal` and for rate limiting.

### Rotating the pepper

```php
'pseudonym' => [
    'current' => 'v2',              // new events use v2
    'keys' => [
        'v1' => env('LN_SECURITY_PSEUDONYM_KEY_V1'),   // keep for old rows
        'v2' => env('LN_SECURITY_PSEUDONYM_KEY_V2'),
    ],
],
```

Keep the previous key for as long as you retain events that reference it.
Rotation never causes a login outage and never invalidates historical rows — it
only means keys minted before and after do not compare equal.

If no key is configured, the package derives one from `APP_KEY`, namespaced by
purpose and version. That is a real secret, so it is safe; the trade-off is that
rotating `APP_KEY` also changes every pseudonym. Set
`LN_SECURITY_PSEUDONYM_KEY` when you want the two lifecycles independent.

## Correlation IDs

- Header name is configurable; default `X-Request-Id`.
- An inbound value is honoured **only** if it matches `[A-Za-z0-9_.:-]{8,128}`.
  Missing, malformed, or oversized values are replaced with a generated ULID, so
  nothing attacker-controlled reaches a log field or a response header.
- The value is echoed in the response.
- Queued magic-link jobs inherit the `correlation_id` of the request that
  dispatched them and receive their own `request_id`, so retries stay
  distinguishable while remaining joinable.

## Sinks

### Log sink (default, enabled)

Writes the envelope as structured context to `logging.channel`, or your app
default. The log message is the event name only; the payload is never
interpolated into the string, which keeps it machine-parseable and makes log
injection impossible.

### Database sink (opt-in)

```bash
php artisan vendor:publish --tag=ln-starter-security-migrations
php artisan migrate
```

```env
LN_SECURITY_AUDIT_DB=true
```

Writes to `ln_security_audit_events`, indexed on event name, `occurred_at`,
request/correlation IDs, outcome, principal key, and attempt ID. Query it with
`LiveNetworks\LnStarter\Models\SecurityAuditEvent`.

### Adding your own sink

```php
use LiveNetworks\LnStarter\Contracts\SecurityAuditSink;
use LiveNetworks\LnStarter\Security\SecurityEvent;
use LiveNetworks\LnStarter\Security\SecurityEventDispatcher;

class SiemSink implements SecurityAuditSink
{
    public function name(): string
    {
        return 'siem';
    }

    public function write(SecurityEvent $event): void
    {
        // Already sanitized. Ship it.
        Http::withToken(config('services.siem.token'))
            ->post(config('services.siem.endpoint'), $event->toArray());
    }
}

// AppServiceProvider::boot()
$this->app->make(SecurityEventDispatcher::class)->extend(new SiemSink());
```

Your sink may throw — from `write()` **or** from `name()`; the dispatcher
guards both. It must not re-enter the dispatcher.

If the audit trail lives on its own connection, set
`logging.database.connection`. Readiness then probes that connection, not the
application default.

## Failure policy

Auditing is **fail-open with respect to login availability**. If a sink throws:

1. the exception is caught and never reaches the auth flow;
2. a message goes to `logging.fallback_channel`;
3. a minimal `security.audit.sink_failed` event goes to the healthy sinks,
   carrying the sink name and the throwable **class** — never its message and
   never the original payload;
4. re-entry is suppressed, so a broken sink cannot storm.

Sink work happens outside the state-machine transaction, so it can never cause a
rollback, and success events are emitted only after a commit.

**Delivery guarantee: at-most-once, best-effort.** A process killed between
commit and sink write loses the event. This is deliberate — the alternative is
failing logins when the audit store is down. If you need stronger guarantees,
write through your own transactional outbox. Readiness checks exist precisely
because the runtime policy is permissive.

## Retention

```bash
php artisan ln-starter:security-audit-prune --dry-run
php artisan ln-starter:security-audit-prune --days=90
php artisan ln-starter:security-audit-prune --days=90 --force   # production
```

Deletion is chunked and selects by primary key, so it works on PostgreSQL (no
`DELETE ... LIMIT`) and never holds a long table lock. Records newer than the
cutoff are never touched. In production, `--force` is required.

```php
// routes/console.php
Schedule::command('ln-starter:security-audit-prune --force')->dailyAt('03:15');
```

## Configuration

| Key | Env | Default |
|---|---|---|
| `logging.enabled` | `LN_SECURITY_LOG_ENABLED` | `true` |
| `logging.channel` | `LN_SECURITY_LOG_CHANNEL` | app default |
| `logging.fallback_channel` | `LN_SECURITY_LOG_FALLBACK_CHANNEL` | app default |
| `logging.request_id_header` | `LN_REQUEST_ID_HEADER` | `X-Request-Id` |
| `logging.max_context_depth` | `LN_SECURITY_LOG_MAX_DEPTH` | `4` |
| `logging.max_context_fields` | `LN_SECURITY_LOG_MAX_FIELDS` | `50` |
| `logging.max_value_length` | `LN_SECURITY_LOG_MAX_VALUE` | `512` |
| `logging.context_allow_list` | — | `[]` |
| `logging.pseudonym.current` | `LN_SECURITY_PSEUDONYM_ID` | `v1` |
| `logging.pseudonym.keys.v1` | `LN_SECURITY_PSEUDONYM_KEY` | derived from `APP_KEY` |
| `logging.database.enabled` | `LN_SECURITY_AUDIT_DB` | `false` |
| `logging.database.connection` | `LN_SECURITY_AUDIT_CONNECTION` | default |
| `logging.database.retention_days` | `LN_SECURITY_AUDIT_RETENTION_DAYS` | `90` |

## Readiness

```bash
php artisan ln-starter:auth-v2-readiness
```

Validates config shape, channel resolution, retention range, pseudonym key
presence, that production is not using a placeholder key, and — when the DB sink
is enabled — table existence, connectivity, and InnoDB on MySQL/MariaDB. An
unknown or null storage engine is a failure, not a pass.

Cheap config checks also run on every boot. Database checks run only in the
command, never per request. Output never contains key material or credentials.

## SIEM integration

The log sink's structured context maps directly onto a JSON formatter:

```php
// config/logging.php
'security' => [
    'driver' => 'monolog',
    'handler' => Monolog\Handler\StreamHandler::class,
    'with' => ['stream' => storage_path('logs/security.log')],
    'formatter' => Monolog\Formatter\JsonFormatter::class,
],
```

```env
LN_SECURITY_LOG_CHANNEL=security
```

### Long-running runtimes

`RequestContext` is a **scoped** binding, not a singleton, so Octane requests
and successive queue jobs never inherit one another's correlation ID. The
dispatcher and the sink registry stay singletons and resolve the current scoped
context per event.

Useful pivots: `correlation_id` to reconstruct a full login attempt across HTTP
and queue, `principal_key` for per-actor activity, `reason_code` for failure
taxonomy, `duration_ms` for latency alerting, and `security.audit.sink_failed`
as a monitor for audit-pipeline health.
