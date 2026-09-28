# Response modes (data / ajax / full)

## Overview

A single controller action serves three outputs from one URL:

| Mode | Trigger | Output |
|---|---|---|
| **data** | `X-LN-Response: data` header, or `Accept: application/json` (non-XHR) | Raw JSON data (for the ln-api-connector SPA) |
| **ajax** | `X-Requested-With: XMLHttpRequest` | JSON of rendered Blade `@section`s via `_ajax` |
| **full** | plain browser request | Full HTML page via `_app` |

Mode is resolved centrally by `LiveNetworks\LnStarter\Http\ResponseMode` in this
priority order:

1. `X-LN-Response: data` present → **data**
2. else `X-Requested-With: XMLHttpRequest` → **ajax**
3. else `wantsJson()` (implies not XHR) → **data**
4. else → **full**

## Data mode contracts

Data mode has **no** `{message, content}` envelope — toasts are client-side. The
helpers on `LNController` emit these shapes:

| Helper | Data-mode body |
|---|---|
| `respondWith($content)` | raw `$content` |
| `respondWithRecord($record, $msg, $status = 200)` | `$record->toRecord()` (or serialized record) |
| `respondWithSync($records, $deleted = [], $syncedAt = null)` | `{ "data": [...], "deleted": [ids], "synced_at": <unix ts> }` |
| `respondWithDeleted($id)` | `{ "ok": true, "id": <id> }` |

Central exception shaping (via `AuthExceptionHandler`, auto-registered):

| Exception | Data-mode response |
|---|---|
| `ValidationException` | 422 `{ "message", "errors" }` (Laravel standard) |
| `BusinessException` | its code (or 422) `{ "message" }` |
| `AuthenticationException` | 401 `{ "message" }` (plain string) + `WWW-Authenticate` header |
| 403 `HttpException` | 403 `{ "message" }` (plain string) |
| `VersionConflictException` | 409 `{ "remote": <server record>, "field_diffs": null }` — envelope matches the ln-ashlar coordinator parser |

In **ajax** mode these still return the legacy `Message` DTO envelope; in **full**
mode they fall through to Laravel's redirect-back / error page.

## Record shape — `toRecord()`

`LNReadModel` implements `ProvidesRecord` with a default
`toRecord(): array { return $this->toArray(); }`. Override it on a read model to
shape the exact record contract sent to the connector (the data-mode analogue of
`toFormPayload()`):

```php
class VProduct extends LNReadModel
{
    protected $table = 'v_products';

    public function toRecord(): array
    {
        return [
            'id'        => (int) $this->id,
            'name'      => $this->name,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
```

`RecordSerializer::toArray()` resolves any record-like value (model implementing
`ProvidesRecord`, any object with `toRecord()`, `Arrayable`, `JsonSerializable`, or
array), so `respondWithRecord()` also works with write models and plain arrays.

## Controller pattern

```php
class ProductController extends LNController
{
    public function index()
    {
        // GET sync feed for the connector; full page for a browser.
        return $this->view('products.index')
            ->respondWithSync(VProduct::all());
    }

    public function store(StoreProductRequest $request)
    {
        $product = Product::create($request->validated());

        // Data mode → raw record JSON; ajax/full → view render + toast.
        return $this->view('products.index')->respondWithRecord(
            VProduct::findOrFail($product->id),
            new Message('success', __('Created'), __('Product created.'))
        );
    }

    public function update(string $locale, Product $product, UpdateProductRequest $request)
    {
        // Opt-in optimistic locking (no-op when expected_version is absent).
        $this->guardVersion($product, $request->integer('expected_version') ?: null);

        $product->update($request->validated());

        return $this->view('products.index')->respondWithRecord(
            VProduct::findOrFail($product->id)
        );
    }

    public function destroy(string $locale, Product $product)
    {
        $id = (int) $product->id;
        $product->delete();

        return $this->view('products.index')->respondWithDeleted($id);
    }
}
```

Domain errors are thrown, not hand-rendered — the central handler shapes them:

```php
if ($blocked) {
    throw new BusinessException(__('This package is assigned to tenants.'), __('Cannot delete'), 422);
}
```

## CSRF in data mode

Data mode uses **no synchronizer token**. The `X-LN-Response: data` header is the
CSRF proof: cross-origin HTML forms cannot set custom headers, and a custom-header
fetch triggers a CORS preflight an attacker origin cannot satisfy. Two pieces:

- `EnforceDataResponseHeader` (alias `ln.data`): rejects (403) state-changing
  requests that lack the header. Apply it to the data-API route group.
- `VerifyCsrfToken`: skips token validation for header-bearing requests on
  `ln.data` routes.

```php
Route::middleware(['auth:sanctum', 'ln.data'])->group(function () {
    Route::apiResource('products', ProductController::class);
});
```

**Assumptions:** session cookie `SameSite=Lax` (Laravel default) and no permissive
CORS on these routes.

## Blade (ajax / full modes)

```blade
@extends('layouts._ln')

@section('title', 'Products')

@section('content')
    @foreach($response['content'] as $product)
        <tr><td>{{ $product->name }}</td></tr>
    @endforeach
@endsection
```

`_ln` switches between `_app` (full) and `_ajax` (sections) automatically. Data mode
never reaches Blade — it returns JSON directly from the controller.

## Why this approach

1. **No route duplication** — one URL serves browser, ajax, and the SPA connector.
2. **No per-controller JSON hacks** — record shape, validation, domain errors, and
   conflicts are shaped in one place.
3. **API-ready from day one** — `Accept: application/json` yields raw data.
4. **Offline-friendly CSRF** — header-as-proof survives an offline replay queue.
