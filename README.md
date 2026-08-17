# LN-Starter

Laravel foundation package by Live Networks. Base classes and conventions for building dual-mode (browser + API) Laravel applications.

**Laravel 12 and 13 are the supported deployment targets** and are gated on
`composer audit`. Laravel 11 remains only as an end-of-life compatibility lane
so existing applications can still install the package; a green Laravel 11 lane
proves installability, not that Laravel 11 is security-supported. Do not deploy
it as a production target.

MySQL/MariaDB (InnoDB) and PostgreSQL are supported databases. SQLite is for
development and unit tests only — auth v2 refuses it in production because it
cannot provide the row locking that single-use proof consumption depends on.

See [`docs/deployment.md`](docs/deployment.md) and
[ADR 0003](docs/adr/0003-auth-v2-production-and-release-contract.md).

## Core principle

**One URL, one controller, one logic — output adapts to the request type.**

A single route serves both the browser (HTML via Blade) and API clients (JSON). The controller writes business logic once; the response layer inspects the `Accept` header and `X-Requested-With` to decide how to render the result.

```
POST /members
├── Browser form submit  → HTML redirect / Blade view
├── AJAX (XMLHttpRequest) → JSON with rendered Blade sections
└── API (Accept: application/json) → pure JSON payload
```

## Installation

```bash
composer require livenetworks/ln-starter
```

The service provider auto-registers via Laravel package discovery.

### Publish assets

```bash
# Config (includes auth settings)
php artisan vendor:publish --tag=ln-starter-config

# Layouts (_app, _ln, _ajax, _auth → resources/views/layouts/)
php artisan vendor:publish --tag=ln-starter-layouts

# Auth views & email templates (override package views)
php artisan vendor:publish --tag=ln-starter-views

# Auth SCSS (publishes to resources/scss/auth.scss)
php artisan vendor:publish --tag=ln-starter-auth-css

# Auth v2 migration (magic_login_attempts)
php artisan vendor:publish --tag=ln-starter-migrations

# Optional Sanctum PAT migration (not used by built-in auth)
php artisan vendor:publish --tag=ln-starter-sanctum-migrations

# Stubs (for scaffolding new controllers/models)
php artisan vendor:publish --tag=ln-starter-stubs

# Claude AI skill (publishes to .claude/skills/ln-starter/)
php artisan vendor:publish --tag=ln-starter-skill
```

## Package contents

```
ln-starter/
├── src/
│   ├── Http/
│   │   ├── LNController.php          # Base controller with dual-mode response
│   │   ├── Controllers/
│   │   │   └── AuthController.php     # Passwordless auth (magic link)
│   │   └── Middleware/
│   │       ├── AuthenticateWithSanctum.php   # Bearer token auth
│   │       ├── AuthorizationFromCookie.php   # Cookie→Bearer bridge
│   │       ├── DisableCsrf.php               # CSRF skip marker
│   │       └── VerifyCsrfToken.php           # CSRF with route-aware skip
│   ├── View/
│   │   └── LNViewComposer.php        # Base view composer
│   ├── Models/
│   │   ├── LNReadModel.php            # Read-only Eloquent (DB views)
│   │   ├── LNWriteModel.php           # Write Eloquent (no timestamps)
│   │   ├── MagicLoginAttempt.php       # Auth v2 attempt state
│   │   └── MagicLinkToken.php          # Legacy cleanup compatibility
│   ├── Jobs/
│   │   └── ProcessMagicLoginRequest.php # Encrypted async mail job
│   ├── Mail/
│   │   └── MagicLinkMail.php          # Magic link email
│   ├── DTOs/
│   │   └── Message.php                # Unified response message
│   ├── Exceptions/
│   │   └── BusinessException.php      # Domain-level exception
│   └── LnStarterServiceProvider.php   # Package service provider
├── config/
│   └── ln-starter.php                 # Package configuration
├── routes/
│   └── auth.php                       # Auth routes (loaded when enabled)
├── database/
│   └── migrations/
│       └── auth-v2/create_magic_login_attempts_table.php
├── resources/
│   ├── scss/
│   │   └── auth.scss                  # Auth styles (BEM, ln-acme mixins)
│   └── views/
│       ├── layouts/
│       │   ├── _ln.blade.php          # Layout switcher (AJAX vs full page)
│       │   ├── _ajax.blade.php        # JSON response layout for AJAX
│       │   └── _auth.blade.php        # Minimal auth layout
│       ├── auth/
│       │   ├── login.blade.php        # Login form (magic link)
│       │   ├── magic_code.blade.php   # Six-digit code form
│       │   └── magic.blade.php        # Token-free link confirmation
│       └── emails/
│           └── magic-link.blade.php   # Magic link email template
├── docs/
│   ├── auth.md                        # Auth module setup & flow
│   ├── auth-v2-test-matrix.md         # Security acceptance matrix for auth v2
│   ├── security-logging.md            # Security events, sinks, retention
│   ├── deployment.md                  # Deploy checklist, runbook, rollback
│   ├── adr/
│   │   ├── 0001-magic-link-authentication-v2.md # Accepted auth v2 design
│   │   ├── 0002-security-audit-logging-and-observability.md # Audit pipeline
│   │   └── 0003-auth-v2-production-and-release-contract.md  # Support matrix
│   ├── dual-mode-response.md          # How the response system works
│   ├── read-write-models.md           # Read/write model separation
│   ├── message-dto.md                 # Message DTO usage
│   ├── middleware.md                   # Middleware reference
│   ├── view-composers.md              # View composer pattern
│   └── conventions.md                 # Naming and architecture conventions
├── stubs/
│   ├── controller.stub                # Controller scaffold
│   ├── read-model.stub                # Read model scaffold
│   └── write-model.stub               # Write model scaffold
├── composer.json
├── CLAUDE.md                          # AI instructions (lean, for Claude Code)
├── skills/
│   └── ln-starter/
│       └── SKILL.md                   # Claude AI skill (detailed, publishable)
├── CHANGELOG.md
└── LICENSE
```

## Architecture

### LNController

Every controller extends `LNController`. The key method is `respondWith()`:

```php
class MemberController extends LNController
{
    public function index()
    {
        $members = VMember::all();

        return $this->view('members.index')
            ->respondWith($members);
    }

    public function store(Request $request)
    {
        $member = Member::create($request->validated());

        return $this->view('members.index')
            ->respondWith(
                $member,
                new Message('success', 'Created', 'Member created successfully.')
            );
    }
}
```

How `respondWith()` decides the output:

| Condition | Output |
|---|---|
| `Accept: application/json` (not AJAX) | Pure JSON: `{ message, content }` |
| `X-Requested-With: XMLHttpRequest` | Blade view through `_ajax` layout → JSON with rendered sections |
| Regular browser request | Full Blade view through `_app` layout |

The `_ln.blade.php` layout handles the AJAX/full-page switch:

```blade
@extends(request()->header('X-Requested-With') === 'XMLHttpRequest'
    ? 'layouts._ajax'
    : 'layouts._app')
```

### LNViewComposer

Base class for view composers. Provides access to the `$response['content']` array that `respondWith()` populates. All composers follow the same pattern: read response data, enrich it, write it back.

```php
class MemberFormComposer extends LNViewComposer
{
    public function enrich(array &$content, View $view): void
    {
        $content['lodges'] = Cache::remember('lodges.all', INF,
            fn() => Lodge::all()
        );

        $content['grades'] = Cache::remember('grades.ordered', INF,
            fn() => Grade::orderBy('sequence')->get()
        );
    }
}
```

The base class handles the boilerplate of extracting `$response['content']` from the view data and writing it back. Your composer only implements `enrich()`.

Register composers in a service provider:

```php
View::composer('members.form', MemberFormComposer::class);
View::composer('members.index', MembersIndexComposer::class);
```

### Read/Write model separation

- **`LNReadModel`** — for database views and read-only tables. All write operations (`create`, `update`, `delete`, `save`) return `false`. No timestamps.
- **`LNWriteModel`** — standard Eloquent model for write operations. No timestamps by default (override in child if needed).

```php
// Read-only: backed by a DB view
class VMember extends LNReadModel
{
    protected $table = 'v_members';
}

// Writable: backed by a real table
class Member extends LNWriteModel
{
    protected $table = 'members';
    protected $fillable = ['name', 'email', 'lodge_id'];
}
```

### Message DTO

Unified message object for all response types. JSON-serializable.

```php
$message = new Message(
    type: 'success',      // success | error | warning | info
    title: 'Created',
    body: 'Member was created successfully.',
    data: ['id' => 42]    // optional extra data
);
```

### Middleware

| Middleware | Purpose |
|---|---|
| `AuthenticateWithSanctum` | Validates bearer tokens from `Authorization` header |
| `AuthorizationFromCookie` | Deprecated legacy cookie-to-bearer bridge; not used by auth v2 |
| `DisableCsrf` | Bearer-only marker middleware (`disable-csrf:bearer`) |
| `VerifyCsrfToken` | Extended Laravel CSRF that exempts only marked requests with an explicit bearer header |

### BusinessException

Domain-level exception for business rule violations. Carries a `title` and translatable message.

```php
throw new BusinessException(
    message: 'Member already exists with this email.',
    title: 'Duplicate',
    code: 409
);
```

## Configuration

After publishing, edit `config/ln-starter.php`:

```php
return [
    // Layout for full-page requests (your app provides this)
    'layout' => 'layouts._app',

    // Layout for AJAX requests (provided by package)
    'ajax_layout' => 'layouts._ajax',

    // Middleware aliases registered by the package
    'middleware_aliases' => [
        'sanctum.token'   => \LiveNetworks\LnStarter\Http\Middleware\AuthenticateWithSanctum::class,
        'cookie.auth'     => \LiveNetworks\LnStarter\Http\Middleware\AuthorizationFromCookie::class,
        'disable-csrf'    => \LiveNetworks\LnStarter\Http\Middleware\DisableCsrf::class,
    ],

    // Passwordless auth (magic link)
    'auth' => [
        'enabled'      => false,        // opt-in
        'user_model'   => 'App\\Models\\User',
        'token_expiry' => 15,           // minutes
        'home_route'   => 'home',       // route name after login
        'mail_subject' => 'Magic Link Login',
        'layout'       => 'layouts._auth',
    ],
];
```

## Auth module (Passwordless / Magic Link)

The package includes an opt-in passwordless authentication module using magic links. Disabled by default.

### Setup

**1. Enable in config**

```php
// config/ln-starter.php
'auth' => [
    'enabled' => true,
    // ...
],
```

**2. Publish auth styles and add to Vite**

```bash
php artisan vendor:publish --tag=ln-starter-auth-css
```

This copies `auth.scss` to `resources/scss/auth.scss`. Add it to your `vite.config.js` input array:

```js
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/scss/auth.scss',  // ← add this
                'resources/scss/app.scss',
                'resources/js/app.js',
            ],
        }),
    ],
});
```

Then build:

```bash
npm run build
```

> The auth SCSS is **fully standalone** — no `ln-acme` or other npm peer dependency required. All styles (custom properties, reset, animations, BEM components) are self-contained.

**3. User model prerequisites**

Your `User` model must:
- Implement Laravel's `Authenticatable` contract (the normal Laravel user model)
- Expose its canonical email address

```php
class User extends Authenticatable
{
    protected $fillable = ['email'];
}
```

**4. Run migrations**

```bash
php artisan migrate
```

This creates the additive `magic_login_attempts` table. The migration is loaded automatically when auth is enabled. To publish it for customization:

```bash
php artisan vendor:publish --tag=ln-starter-migrations
```

**5. Configure the auth-v2 pepper, queue, and session**

```dotenv
LN_AUTH_PEPPER_ID=v1
LN_AUTH_PEPPER=base64:REPLACE_WITH_AT_LEAST_32_RANDOM_BYTES
QUEUE_CONNECTION=database
SESSION_DRIVER=database
```

Run an asynchronous queue worker in production. Built-in auth uses only the
Laravel `web` session; it does not require `HasApiTokens`, an `auth_token`
cookie, or the cookie-to-header middleware.

**6. Audit upgrades from auth v1**

```bash
php artisan ln-starter:auth-v2-audit
php artisan ln-starter:auth-v2-readiness
php artisan ln-starter:auth-v2-cutover --force
```

### Routes registered

| Method | URI | Name | Purpose |
|--------|-----|------|---------|
| GET | `/login` | `login` | Login form |
| POST | `/auth/magic-link` | `login.magic-link` | Send magic link email |
| GET | `/auth/magic/code` | `auth.magic.code.form` | Six-digit code form |
| POST | `/auth/magic/code` | `auth.magic.code` | Consume code in requesting session |
| GET | `/auth/magic/{token}` | `auth.magic.link.open` | Exchange URL proof for token-free context |
| GET | `/auth/magic/confirm/{context}` | `auth.magic.link.confirm` | Show confirmation page |
| POST | `/auth/magic/confirm/{context}` | `auth.magic.link.consume` | Consume link and authenticate session |
| POST | `/logout` | `logout` | Invalidate session and redirect to login |

### Flow

```
1. User enters an email; the public response is identical for every address.
2. An encrypted queue job checks eligibility and sends a link plus six-digit code.
3. The requesting browser can enter the code; the link can be opened elsewhere.
4. Link GET exchanges the URL secret for a bounded session context and redirects.
5. A CSRF-protected POST consumes either proof, starts a web session, and revokes siblings.
```

Render a CSRF-safe logout form with:

```blade
<x-ln.logout-form class="nav-logout">{{ __('Sign out') }}</x-ln.logout-form>
```

> **Why two steps?** Email scanners pre-fetch URLs via GET. GET never authenticates;
> only the explicit, CSRF-protected confirmation POST consumes the proof.

### Customizing views

Publish and override:

```bash
php artisan vendor:publish --tag=ln-starter-views
```

Views are published to `resources/views/vendor/ln-starter/`. Edit:
- `auth/login.blade.php` — login form
- `auth/magic_code.blade.php` — code entry
- `auth/magic.blade.php` — token-free magic link confirmation
- `emails/magic-link.blade.php` — link plus code email
- `layouts/_auth.blade.php` — auth page layout

Or point `config('ln-starter.auth.layout')` to your own layout.

### Translating

All user-facing strings use `__()`. Publish Laravel lang files and translate as needed.

## Project-specific extensions

The package provides the foundation. Your project adds:

- **App layout** (`_app.blade.php`) — sidebar, header, footer, project-specific assets
- **Auth layout** (`_auth.blade.php`) — login/register pages
- **RBAC middleware** (`CheckPermission`, `CheckRole`) — domain-specific authorization
- **Domain middleware** (`EnsureMemberExists`) — domain-specific context
- **Form Requests** — validation is always project-specific
- **View Composers** — extend `LNViewComposer`, implement `enrich()` to add view-specific data
- **Traits** — reusable controller behaviors (pagination, filtering)

## AI skill

The package includes a Claude AI skill for code generation. After publishing:

```bash
php artisan vendor:publish --tag=ln-starter-skill
```

The skill is copied to `.claude/skills/ln-starter/SKILL.md`. Claude will read it automatically and generate code that follows LN-Starter conventions — correct base classes, dual-mode response pattern, read/write model separation, view composer pattern, etc.

## License

MIT © Live Networks
