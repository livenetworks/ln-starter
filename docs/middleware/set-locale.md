# SetLocale Middleware

**Alias:** `ln.locale`
**Class:** `LiveNetworks\LnStarter\Http\Middleware\SetLocale`

URL-prefix multilanguage middleware. Reads the locale from the first URL segment, validates it, and sets the application locale.

## URL Pattern

```
/{locale}/path/to/page
/mk/documents/new      → Macedonian interface
/en/documents/new      → English interface
/sq/documents/new      → Albanian interface
```

## Setup

### 1. Define supported languages in `config/app.php`

```php
'locale' => 'mk',

'languages' => [
    'mk' => 'Македонски',
    'en' => 'English',
    'sq' => 'Shqip',
],
```

Keys are ISO 639-1 language codes. Values are display names (used in language switchers).

### 2. Wrap routes in a locale prefix group

```php
// routes/web.php
Route::prefix('{locale}')->middleware('ln.locale')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('home');
    Route::get('/documents/new', [DocumentController::class, 'create'])->name('documents.create');
    Route::resource('/documents', DocumentController::class);
});
```

### 3. Redirect root to default locale

```php
// routes/web.php (outside the locale group)
Route::get('/', fn () => redirect('/' . config('app.locale')));
```

## Behavior

| Scenario | Result |
|----------|--------|
| `/mk/documents/new` | Locale set to `mk`, request proceeds |
| `/en/documents/new` | Locale set to `en`, request proceeds |
| `/documents/new` (no locale) | Redirect → `/mk/documents/new` (default) |
| `/xx/documents/new` (invalid) | Redirect → `/mk/documents/new` (default) |
| `/en/documents?page=2` | Locale set to `en`, query string preserved |

## What it does

1. Reads `{locale}` route parameter from the first URL segment
2. Validates against `config('app.languages')` keys
3. If valid: calls `app()->setLocale($locale)` and shares `$currentLocale` with all views
4. If invalid or missing: redirects to the same path with default locale prefix, preserving query string

## View integration

The middleware shares `$currentLocale` with all Blade views via `view()->share()`. Use it in templates:

```blade
{{-- Language switcher --}}
@foreach(config('app.languages') as $code => $name)
    <a href="/{{ $code }}/{{ $pathWithoutLocale }}"
       @if($code === $currentLocale) data-active @endif>
        {{ $name }}
    </a>
@endforeach
```

## Route generation

When generating URLs with `route()`, include the locale parameter:

```php
route('documents.create', ['locale' => app()->getLocale()])
// → /mk/documents/new
```

Or use a helper/macro to inject it automatically (project-level decision).

## Fallback behavior

If `config('app.languages')` is empty, the middleware falls back to `config('app.locale')` and `config('app.fallback_locale')` as the only supported locales.

## Configuration

No additional configuration needed beyond `config('app.languages')`. The middleware alias `ln.locale` is registered automatically by `LnStarterServiceProvider`.
