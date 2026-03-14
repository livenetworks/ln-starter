# Dual-mode response system

## Overview

The core idea: a single controller action serves browser requests (HTML), AJAX requests (JSON with rendered HTML sections), and pure API requests (JSON data).

## How it works

### Request flow

```
Client Request
    │
    ├─ Accept: application/json (not XHR)
    │   └─ respondWith() returns response()->json()
    │
    ├─ X-Requested-With: XMLHttpRequest
    │   └─ respondWith() renders Blade view through _ajax layout
    │       └─ _ajax extracts @sections → returns JSON { title, message, content: { sectionName: html } }
    │
    └─ Regular browser request
        └─ respondWith() renders Blade view through _app layout
            └─ Full HTML page
```

### The `respondWith()` method

```php
protected function respondWith($content, Message $message = null)
```

Parameters:
- `$content` — any data (collection, model, array, string)
- `$message` — optional `Message` DTO for status feedback

The method wraps both into a response array:
```php
$response = [
    'message' => $message,
    'content' => $content
];
```

Then decides the output format:

1. **Pure JSON API**: `wantsJson() && !ajax()` → `response()->json($response)`
2. **AJAX or browser**: renders the Blade view set via `$this->view()`, passing `$response` and `$message` to the template

### The `view()` method

```php
protected function view(string $view)
```

Sets which Blade view to render. Returns `$this` for chaining:

```php
return $this->view('members.index')->respondWith($members);
```

### Layout switching: `_ln.blade.php`

The `_ln` layout is the entry point for all views. It detects whether the request is AJAX and extends the appropriate parent:

```blade
@extends(request()->header('X-Requested-With') === 'XMLHttpRequest'
    ? 'layouts._ajax'
    : 'layouts._app')
```

Your project provides `layouts._app` (the full HTML shell). The package provides `layouts._ajax`.

### The `_ajax` layout

When a view is rendered through `_ajax`, it extracts all `@section` content and returns them as a JSON object:

```json
{
    "title": "Members",
    "message": { "type": "success", "title": "OK", "body": "..." },
    "content": {
        "content": "<div>...rendered HTML...</div>",
        "sidebar": "<nav>...</nav>"
    }
}
```

This allows the frontend JS to receive pre-rendered HTML sections and inject them into the DOM without a full page reload.

## Usage pattern

### Controller

```php
class MemberController extends LNController
{
    public function index()
    {
        $members = VMember::paginate(25);

        return $this->view('members.index')
            ->respondWith($members);
    }

    public function store(StoreMemberRequest $request)
    {
        $member = Member::create($request->validated());

        return $this->view('members.show')
            ->respondWith(
                $member,
                new Message('success', 'Created', 'Member created.')
            );
    }
}
```

### Blade view

```blade
@extends('layouts._ln')

@section('title', 'Members')

@section('content')
    <table>
        @foreach($response['content'] as $member)
            <tr><td>{{ $member->name }}</td></tr>
        @endforeach
    </table>
@endsection
```

### Routes

```php
// Same route, same controller — works for browser AND API
Route::resource('members', MemberController::class);
```

### Frontend JS (AJAX consumption)

```javascript
fetch('/members', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
})
.then(r => r.json())
.then(data => {
    document.getElementById('content').innerHTML = data.content.content;
    if (data.message) showToast(data.message);
});
```

### API consumption

```javascript
fetch('/members', {
    headers: {
        'Accept': 'application/json',
        'Authorization': 'Bearer ' + token
    }
})
.then(r => r.json())
.then(data => {
    // data.content = raw data (collection/model)
    // data.message = Message DTO or null
});
```

## Why this approach

1. **No route duplication** — no `/api/members` alongside `/members`
2. **No logic duplication** — validation, authorization, business logic written once
3. **Progressive enhancement** — works without JS (full page), enhanced with JS (AJAX sections)
4. **API-ready from day one** — same endpoint, different Accept header
