# LN-Starter — Laravel development skill

Use this skill when working on any Laravel project that uses the `livenetworks/ln-starter` package. It applies to controller creation, model creation, view composition, middleware usage, and any code generation that touches the request/response cycle.

## Trigger conditions

- User asks to create a controller, model, view, or composer
- User asks about request/response handling in an LN project
- User mentions `LNController`, `LNReadModel`, `LNWriteModel`, `respondWith`, or `LNViewComposer`
- User asks to add a new feature/CRUD to a Laravel project using LN-Starter
- User mentions dual-mode, AJAX response, or same-URL API

## Core rules

### 1. Controllers MUST extend LNController

```php
use LiveNetworks\LnStarter\Http\LNController;

class ProductController extends LNController
{
    // ...
}
```

NEVER extend Laravel's base `Controller` or `Illuminate\Routing\Controller` directly.

### 2. Use the view()->respondWith() pattern

Every controller action that returns a response MUST use:

```php
return $this->view('products.index')->respondWith($data);
return $this->view('products.show')->respondWith($product, $message);
```

NEVER return `view()` directly. NEVER return `response()->json()` directly. The `respondWith()` method handles format detection automatically.

### 3. Read models for views, write models for tables

```php
// CORRECT — reading data
$products = VProduct::where('active', true)->paginate(25);

// CORRECT — writing data
$product = Product::create($validated);
$product->update($validated);

// WRONG — writing to a read model
$vProduct = VProduct::find(1);
$vProduct->update($data); // Returns false, does nothing
```

Naming:
- Read model: `V` prefix → `VProduct`, `VMember`, `VOrder`
- Write model: no prefix → `Product`, `Member`, `Order`
- DB view: `v_` prefix → `v_products`, `v_members`, `v_orders`
- DB table: no prefix → `products`, `members`, `orders`

### 4. Message DTO for all status communication

```php
use LiveNetworks\LnStarter\DTOs\Message;

// After successful create/update/delete
new Message('success', 'Created', 'Product was created.')

// After failed business logic
new Message('error', 'Failed', 'Insufficient stock.')

// Informational
new Message('info', 'Note', 'Prices will update at midnight.')

// Warning
new Message('warning', 'Attention', 'This product is being discontinued.')
```

Types: `success`, `error`, `warning`, `info` — nothing else.

### 5. View composers extend LNViewComposer

```php
use LiveNetworks\LnStarter\View\LNViewComposer;

class ProductFormComposer extends LNViewComposer
{
    public function enrich(array &$content, View $view): void
    {
        $content['categories'] = Category::orderBy('name')->get();
    }
}
```

NEVER manipulate `$view->getData()` directly. NEVER use `$view->with()` directly. The base class handles extraction and re-injection of `$response['content']`.

Pattern for composers that depend on existing response data:

```php
public function enrich(array &$content, View $view): void
{
    $productId = $content['product']->id ?? null;
    if ($productId) {
        $content['reviews'] = VReview::where('product_id', $productId)->get();
    }
}
```

### 6. BusinessException for domain errors

```php
use LiveNetworks\LnStarter\Exceptions\BusinessException;

if ($product->stock < $quantity) {
    throw new BusinessException(
        message: 'Not enough stock available.',
        title: 'Insufficient stock',
        code: 422
    );
}
```

Use `BusinessException` for business rule violations. Use `abort()` for HTTP-level errors (401, 403, 404). NEVER use generic `\Exception` for business logic.

### 7. No route duplication

```php
// CORRECT — one route serves browser + API
Route::resource('products', ProductController::class);

// WRONG — separate API routes with duplicate logic
Route::resource('products', ProductController::class);
Route::apiResource('api/products', ApiProductController::class);
```

### 8. Blade views extend _ln

```blade
@extends('ln-starter::layouts._ln')

@section('title', 'Products')

@section('content')
    {{-- $response['content'] contains your data --}}
    @foreach($response['content'] as $product)
        ...
    @endforeach
@endsection
```

The `_ln` layout automatically switches between full-page (extending your app layout) and AJAX (returning JSON sections).

### 9. No timestamps by default

Both `LNReadModel` and `LNWriteModel` have `$timestamps = false`. Explicitly opt in when needed:

```php
class AuditLog extends LNWriteModel
{
    public $timestamps = true;
}
```

### 10. CSRF strategy

- Authenticated users skip CSRF automatically (via `VerifyCsrfToken`)
- API routes with token auth: add `disable-csrf` middleware
- Public forms: CSRF works normally via `@csrf`

## Middleware aliases

| Alias | Class | Purpose |
|---|---|---|
| `sanctum.token` | `AuthenticateWithSanctum` | Bearer token validation |
| `cookie.auth` | `AuthorizationFromCookie` | Cookie → Authorization header bridge |
| `disable-csrf` | `DisableCsrf` | Marker for CSRF skip |

Stack order for dual-mode routes: `cookie.auth` → `sanctum.token` → (web middleware group handles CSRF)

## Scaffolding a new feature

When asked to create a new CRUD or feature, follow this order:

1. **Migration** — create table + create view (if needed)
2. **Write model** — extends `LNWriteModel`, set `$table` and `$fillable`
3. **Read model** — extends `LNReadModel`, set `$table` (if using a DB view)
4. **Controller** — extends `LNController`, use `view()->respondWith()`
5. **View composer** — extends `LNViewComposer`, implement `enrich()` for form dropdowns etc.
6. **Blade views** — extend `ln-starter::layouts._ln`
7. **Routes** — single `Route::resource()`, no API duplication
8. **Register composer** — in a service provider

## Stack context

- **Backend**: Laravel 11+, Blade SSR (no SPA frameworks)
- **Database**: PostgreSQL, JSONB for dynamic/flexible entities
- **Frontend**: Vanilla JS (IIFE pattern), SCSS, ln-acme component library
- **Auth**: Laravel Sanctum (token-based), passwordless (Passkey + Magic Link)
- **Philosophy**: server-side rendering, minimal client-side JS, long-term stability over trends
