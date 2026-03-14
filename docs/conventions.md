# Conventions

## Naming

| Item | Convention | Example |
|---|---|---|
| Read model (DB view) | `V` prefix | `VMember`, `VTransaction` |
| Write model (table) | No prefix | `Member`, `Transaction` |
| DB view name | `v_` prefix | `v_members`, `v_transactions` |
| Controller | Resource-style | `MemberController`, `TransactionController` |
| Message types | Lowercase string | `success`, `error`, `warning`, `info` |
| Middleware alias | Dot-separated | `sanctum.token`, `cookie.auth` |

## Architecture rules

### One URL, dual output

Every controller action should work for both browser and API. Use `respondWith()` — never create separate `/api/` routes for the same logic.

```php
// Good — one route, one action
Route::resource('members', MemberController::class);

// Bad — duplicated logic
Route::resource('members', MemberController::class);       // browser
Route::resource('api/members', ApiMemberController::class); // API
```

### Controller structure

```php
class MemberController extends LNController
{
    // 1. Set view
    // 2. Execute logic
    // 3. Return respondWith()
    public function index()
    {
        $members = VMember::paginate(25);

        return $this->view('members.index')
            ->respondWith($members);
    }
}
```

### Read before write

When displaying data, use the read model. When modifying data, use the write model:

```php
// Display: read model (joins, computed fields from view)
$member = VMember::findOrFail($id);

// Modify: write model (direct table access)
$member = Member::findOrFail($id);
$member->update($data);
```

### Exception handling

Use `BusinessException` for domain-level errors. Let Laravel handle HTTP-level errors (404, 401, 403).

```php
// Good — domain error
throw new BusinessException('Email already registered.', 'Duplicate', 409);

// Good — HTTP error (Laravel handles this)
abort(404);

// Bad — mixing concerns
throw new \Exception('Not found'); // too generic
```

### Blade views

Views that participate in dual-mode response should extend `_ln`:

```blade
@extends('ln-starter::layouts._ln')

@section('title', 'Page Title')

@section('content')
    {{-- Your content --}}
@endsection
```

The `_ln` layout handles the switch between full page and AJAX automatically.

### No timestamps by default

Both `LNReadModel` and `LNWriteModel` disable `$timestamps`. Enable explicitly when needed:

```php
class AuditLog extends LNWriteModel
{
    public $timestamps = true; // explicit opt-in
}
```

## File organization in consumer projects

```
app/
├── Http/
│   ├── Controllers/        # Extend LNController
│   ├── Middleware/          # Project-specific (RBAC, domain checks)
│   └── Requests/           # Form validation (always project-specific)
├── Models/
│   ├── Member.php          # Extends LNWriteModel
│   └── VMember.php         # Extends LNReadModel
├── Exceptions/
│   └── ...                 # Extend BusinessException if needed
└── DTOs/
    └── ...                 # Extend Message if needed
```
