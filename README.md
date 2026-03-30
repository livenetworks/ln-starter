# LN-Starter

Laravel foundation package by Live Networks. Base classes and conventions for building dual-mode (browser + API) Laravel applications.

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

# Migrations (magic_link_tokens, personal_access_tokens)
php artisan vendor:publish --tag=ln-starter-migrations

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
│   │   └── MagicLinkToken.php         # Magic link token model
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
│       └── create_magic_link_tokens_table.php
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
│       │   ├── magic_wait.blade.php   # Polling wait page
│       │   └── magic.blade.php        # Magic link confirmation / error
│       └── emails/
│           └── magic-link.blade.php   # Magic link email template
├── docs/
│   ├── auth.md                        # Auth module setup & flow
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
| `AuthorizationFromCookie` | Bridges `auth_token` cookie to `Authorization` header |
| `DisableCsrf` | Marker middleware — marks routes for CSRF skip |
| `VerifyCsrfToken` | Extended Laravel CSRF that respects `DisableCsrf` marker and skips for authenticated users |

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
- Use the `Laravel\Sanctum\HasApiTokens` trait
- Have `'email'` in `$fillable`

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['email'];
}
```

**4. Run migrations**

```bash
php artisan migrate
```

This creates the `magic_link_tokens` table. The migration is loaded automatically when auth is enabled. To publish it for customization:

```bash
php artisan vendor:publish --tag=ln-starter-migrations
```

**5. Exclude auth_token from cookie encryption**

In `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->encryptCookies(except: ['auth_token']);
})
```

**6. Prepend the cookie-to-header middleware**

In `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(
        \LiveNetworks\LnStarter\Http\Middleware\AuthorizationFromCookie::class
    );
})
```

### Routes registered

| Method | URI | Name | Purpose |
|--------|-----|------|---------|
| GET | `/login` | `login` | Login form |
| POST | `/auth/magic-link` | `login.magic-link` | Send magic link email |
| GET | `/magic/wait` | `magic.wait` | "Check your email" polling page |
| GET | `/magic/status` | `magic.status` | Poll endpoint (JSON) |
| GET | `/auth/magic/{token}` | `auth.magic.show` | Show confirmation page (read-only) |
| POST | `/auth/magic/{token}` | `auth.magic.consume` | Consume token, authenticate, redirect |
| POST | `/logout` | `logout` | Revoke token, redirect to login |

### Flow

```
1. User visits /login → enters email → POST /auth/magic-link
2. Package looks up user, generates token, sends email
3. Redirects to /magic/wait → JS polls /magic/status every 2s
4. User clicks email link → GET /auth/magic/{token} → sees confirmation page (token NOT consumed)
5. User clicks "Sign in" → POST /auth/magic/{token} → token consumed, Sanctum token issued, cookie set, redirect to home
6. Meanwhile, polling page detects approval → also issues token → redirects to home
```

> **Why two steps?** Email scanners (Office 365, Avast, Gmail corporate) pre-fetch URLs via GET. The GET route never consumes the token — only the POST (form submit) does. Scanners never submit forms.

### Customizing views

Publish and override:

```bash
php artisan vendor:publish --tag=ln-starter-views
```

Views are published to `resources/views/vendor/ln-starter/`. Edit:
- `auth/login.blade.php` — login form
- `auth/magic_wait.blade.php` — polling page
- `auth/magic.blade.php` — magic link confirmation (valid → sign-in form, invalid → error)
- `emails/magic-link.blade.php` — email template
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
