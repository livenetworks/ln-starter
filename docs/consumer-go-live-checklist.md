# Consumer go-live checklist

Copyable verification list for an application adopting LN-Starter auth v2.

**Blocking** means do not go live. **Non-blocking** means ship, then fix.

Roles: *Dev* = application developer · *Ops* = whoever owns deploy and infra ·
*Sec* = whoever owns the security/compliance position. In a small team these are
the same person; the column says who is accountable, not how many people exist.

## Build time

| # | Item | Owner | Verify with | Expected | Class |
|---|---|---|---|---|---|
| B1 | PHP 8.3+ | Dev | `php -v` | 8.3, 8.4 or 8.5 | Blocking |
| B2 | Laravel 12 or 13 | Dev | `php artisan --version` | Not 11 — compatibility only | Blocking |
| B3 | Package installed | Dev | `composer show livenetworks/ln-starter` | 2.x | Blocking |
| B4 | Package discovery | Dev | `php artisan package:discover` | Provider listed | Blocking |
| B5 | `LN_AUTH_PEPPER` set **before** `composer require` | Dev | `composer require` completes | No boot exception | Blocking |
| B6 | `LN_SECURITY_PSEUDONYM_KEY` set explicitly | Sec | `config('ln-starter.logging.pseudonym.keys')` | Non-empty | Non-blocking |
| B7 | No secrets committed | Sec | `git log -p -- .env` | No matches; `.env` git-ignored | Blocking |
| B8 | Config published and reviewed | Dev | `config/ln-starter.php` exists | Merged, not blindly overwritten | Blocking |
| B9 | Migrations published | Dev | `ls database/migrations` | `create_magic_login_attempts_table` present | Blocking |
| B10 | Audit migration published, if the DB sink is used | Ops | `ls database/migrations` | `create_ln_security_audit_events_table` present | Blocking *if* `LN_SECURITY_AUDIT_DB=true` |
| B11 | Stale v1 views removed | Dev | `php artisan ln-starter:auth-v2-audit` | Exit 0 | Blocking (upgrades only) |
| B12 | Frontend build, if styled auth pages are wanted | Dev | `npm run build` | Manifest lists `resources/scss/auth.scss` | Non-blocking — the page works unstyled |

## Pre-deploy

Most of this block is asserted at once by `ln-starter:auth-v2-readiness`, which
fails closed. Run it; the rows below say what it is checking and why.

| # | Item | Owner | Verify with | Expected | Class |
|---|---|---|---|---|---|
| P1 | **All readiness checks** | Ops | `php artisan ln-starter:auth-v2-readiness` | **Exit 0** | Blocking |
| P2 | `APP_URL` is https with a real host | Ops | readiness | Passes | Blocking |
| P3 | Session cookie secure, http-only, same-site | Ops | readiness | Passes | Blocking |
| P4 | `session.domain` is not a parent domain | Ops | readiness | Passes — a parent domain leaks the session to siblings | Blocking |
| P5 | Trusted proxies configured behind a load balancer | Ops | Request shows the real scheme/IP | Laravel sees https | Blocking behind a proxy |
| P6 | Row-locking database | Ops | readiness | MySQL/InnoDB or PostgreSQL. **Not SQLite** | Blocking |
| P7 | `magic_login_attempts` is InnoDB on MySQL | Ops | readiness | Passes — MyISAM makes `lockForUpdate()` a no-op | Blocking |
| P8 | Queue connection is not `sync` | Ops | readiness | A real queue | Blocking |
| P9 | Mailer configured | Ops | readiness | Configured, and a test message delivered | Blocking |
| P10 | Audit retention decided | Sec | `LN_SECURITY_AUDIT_RETENTION_DAYS` | A deliberate value, not the default by accident | Non-blocking |
| P11 | Prune scheduled | Ops | `php artisan schedule:list` | `ln-starter:security-audit-prune` present | Non-blocking |
| P12 | Caches built | Ops | `config:cache`, `route:cache`, `view:cache` | All succeed | Blocking |
| P13 | Queue workers restarted after deploy | Ops | Worker start time > deploy time | Workers on new code | Blocking |

## Security verification

Perform these against production configuration, in staging or a canary.

| # | Item | Owner | Verify with | Expected | Class |
|---|---|---|---|---|---|
| S1 | CSRF rejection | Sec | `POST /logout` with no token | **419**, not 302 | Blocking |
| S2 | Enumeration resistance | Sec | Request a link for a real and a fake address | Identical response and comparable timing | Blocking |
| S3 | Link GET does not authenticate | Sec | Open the link, stop before confirming | Not logged in; a confirmation page | Blocking |
| S4 | Single-use proof | Sec | Confirm the same link twice | Second attempt refused | Blocking |
| S5 | Code attempt limit | Sec | Submit wrong codes repeatedly | `auth.magic.code.locked` emitted | Blocking |
| S6 | Concurrency | Sec | Confirm link and code simultaneously | Exactly one `auth.magic.proof.accepted` | Blocking |
| S7 | Logout invalidates the session | Sec | Log out, reuse the old session cookie | Not authenticated | Blocking |
| S8 | No secrets in logs | Sec | Search logs for the test address, token and code | **No matches** | Blocking |
| S9 | Correlation id present | Ops | Inspect an auth log line | `correlation_id` populated | Non-blocking |
| S10 | Correlation survives the queue | Ops | Compare request and delivery events | Same `correlation_id` | Non-blocking |
| S11 | Database sink writes the envelope | Ops | Query `ln_security_audit_events` | Row with `event_name`, `outcome`, `principal_key`, `schema_version` | Blocking *if* the sink is enabled |
| S12 | Rate-limit and replay events observable | Ops | Trigger a throttle | `auth.magic.rate_limited` visible | Non-blocking |
| S13 | Published views carry `@csrf` | Sec | Inspect any published auth form | `@csrf` present | Blocking if views were customised |

## Post-deploy

| # | Item | Owner | Verify with | Expected | Class |
|---|---|---|---|---|---|
| D1 | Real magic-link login | Ops | Manual, production config | Authenticated session | Blocking |
| D2 | Real code login | Ops | Manual | Authenticated session | Blocking |
| D3 | Logout | Ops | Manual | Session invalidated | Blocking |
| D4 | Queue delivery healthy | Ops | `delivery.queued` → `delivery.succeeded` | Terminal event follows every queued one | Blocking |
| D5 | Dashboards live | Ops | [`runbooks.md`](runbooks.md) baseline | Signals populated | Non-blocking |
| D6 | Alerts wired | Ops | Fire a test alert | Reaches a human | Non-blocking |
| D7 | Pruning verified | Ops | `ln-starter:security-audit-prune --dry-run` | Reports a plausible count | Non-blocking |
| D8 | Worker health monitored | Ops | Your process supervisor | Workers restart on failure | Blocking |
| D9 | Baseline recorded | Ops | One normal week of event volumes | Thresholds calibrated, not guessed | Non-blocking |

## One-line preflight

```bash
php artisan ln-starter:auth-v2-audit \
  && php artisan ln-starter:auth-v2-readiness \
  && php artisan ln-starter:security-audit-prune --dry-run
```

All three exit 0 → P1–P11 and B11 are satisfied. It does **not** cover the
manual security verification block, which needs a browser and a real mailbox.
