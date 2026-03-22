# Blade Components

## Overview

LN-Starter ships two Blade components — `<x-ln.toast />` and `<x-ln.modal />`. They are registered automatically when the package is installed (no publish needed) and are available in any Blade view.

Both components render minimal HTML that `ln-acme` JS hydrates into the full UI. The Blade templates output `data-*` attributes; ln-acme's JS builds the card structure, icons, animations, and dismiss behavior.

## Toast — `<x-ln.toast />`

Renders a toast notification container with static `data-ln-toast-item` elements for session flash messages. The `ln-acme` JS (`ln-toast.js`) hydrates these into styled cards with icons, close buttons, and auto-dismiss.

### Usage

Place once in your app layout, before `</body>`:

```blade
<x-ln.toast />
```

The scaffold layout (`_app.scaffold.blade.php`) and auth layout (`_auth.blade.php`) already include it.

### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | string | `ln-toast-container` | Container element ID |
| `timeout` | int | `6000` | Auto-dismiss timeout in milliseconds |
| `max` | int | `5` | Maximum number of visible toasts |

### How it works

1. **Server-side**: Blade renders plain `<div data-ln-toast-item data-type="success">Message</div>` elements
2. **Client-side**: `ln-acme` JS finds `data-ln-toast-item` elements and hydrates them into full toast cards with side accent, SVG icon, title, body, and close button
3. **Success messages** — reads `session('ok')`. Set via `redirect()->with('ok', 'Done!')`
4. **Error messages** — reads Laravel's `$errors` bag, deduplicates, renders each as `data-type="error"`
5. **Auto-dismiss** — handled by ln-acme JS based on `data-ln-toast-timeout`
6. **Max limit** — handled by ln-acme JS based on `data-ln-toast-max`

### Rendered HTML (before JS hydration)

```html
<div id="ln-toast-container" data-ln-toast data-ln-toast-timeout="6000" data-ln-toast-max="5">
    <div data-ln-toast-item data-type="success">Record saved.</div>
    <div data-ln-toast-item data-type="error">Email is required.</div>
</div>
```

### Server-side example

```php
// Flash a success message
return redirect()->route('members.index')->with('ok', 'Member created.');

// Validation errors appear automatically
$request->validate(['email' => 'required|email']);
// $errors is populated → toasts render on the redirected page
```

### JS API (programmatic toasts)

```js
// Show a toast from JS (no server round-trip)
window.lnToast.enqueue({
    type: 'success',    // success | error | warn | info
    title: 'Saved',
    message: 'Record updated.'
});

// Error with list
window.lnToast.enqueue({
    type: 'error',
    title: 'Validation',
    message: ['Name is required', 'Email is invalid']
});

// Clear all visible toasts
window.lnToast.clear();
```

## Modal — `<x-ln.modal />`

Renders a modal dialog with a `<form>` as the content root. Follows `ln-acme`'s semantic structure: `.ln-modal > form > header/main/footer` — no BEM wrapper classes.

### Usage

```blade
<x-ln.modal id="delete-member" title="Delete member?" submitText="Delete" action="/members/5" method="POST">
    <p>Are you sure you want to delete this member?</p>
    @method('DELETE')
</x-ln.modal>
```

Open the modal with a trigger button:

```blade
<button data-ln-modal="delete-member">Delete</button>
```

### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | string | `''` | Modal element ID (required for targeting) |
| `title` | string | `''` | Header title text |
| `submitText` | string | `Submit` | Submit button label |
| `action` | string\|null | `null` | Form action URL. When `null`, no `action`/`method` attributes are rendered |
| `method` | string | `POST` | HTTP method (`POST` or `GET`). For PUT/PATCH/DELETE, use `POST` with `@method()` |

### Rendered HTML

```html
<div class="ln-modal" id="delete-member">
    <form action="/members/5" method="POST" data-ln-ajax>
        <header>
            <h3>Delete member?</h3>
            <button type="button" class="ln-icon-close" data-ln-modal-close aria-label="Close"></button>
        </header>
        <main>
            <p>Are you sure?</p>
            <input type="hidden" name="_method" value="DELETE">
        </main>
        <footer>
            <button type="button" data-ln-modal-close>Cancel</button>
            <button type="submit">Delete</button>
        </footer>
    </form>
</div>
```

Key points:
- `<form>` is always the direct child of `.ln-modal` — ln-acme CSS targets `.ln-modal > form`
- `header`, `main`, `footer` are semantic HTML tags — no BEM classes needed
- Close button uses `ln-icon-close` class and `data-ln-modal-close` attribute
- Footer buttons get `@include btn` styling automatically from ln-acme CSS
- Non-submit buttons must have `type="button"` to prevent form submission

### AJAX submission

The form has `data-ln-ajax`, which means `ln-acme` JS intercepts the form submission and sends it as an AJAX request. The response `message` object is automatically shown as a toast.

### Patterns

#### Confirmation dialog

```blade
<x-ln.modal id="confirm-delete" title="Confirm deletion" submitText="Delete" action="{{ route('members.destroy', $member) }}" method="POST">
    @method('DELETE')
    <p>{{ __('This action cannot be undone.') }}</p>
</x-ln.modal>
```

#### Inline form

```blade
<x-ln.modal id="add-note" title="Add note" submitText="Save" action="{{ route('notes.store') }}">
    <label>{{ __('Note') }} <textarea name="body" required></textarea></label>
</x-ln.modal>
```

#### Modal without form action

When `action` is `null`, the form tag renders without `action` and `method` attributes — useful for JS-only modals:

```blade
<x-ln.modal id="preview" title="Preview">
    <div id="preview-content"></div>
</x-ln.modal>
```

#### Modal sizes

Sizes are controlled via SCSS mixins in your project, not CSS classes:

```scss
#my-modal > form { @include modal-lg; }
```

Available: `modal-sm` (28rem), `modal-md` (32rem), `modal-lg` (42rem), `modal-xl` (48rem).

### JS API

```js
window.lnModal.open('my-modal');
window.lnModal.close('my-modal');
window.lnModal.toggle('my-modal');
```

### Behavior

- ESC closes all open modals
- Body scroll locked when modal open (`body.ln-modal-open`)
- Backdrop blur + opacity overlay
- Slide-in animation
- MutationObserver watches for dynamically added modals and triggers

## Peer dependency

Both components require `ln-acme` installed via npm. The CSS and JS are provided by ln-acme; ln-starter only provides the Blade templates.

## No publish needed

The components are registered via `Blade::component()` in the service provider and load their templates from the package. They work immediately after `composer require`.

To override a component's template, publish views:

```bash
php artisan vendor:publish --tag=ln-starter-views
```

Published to `resources/views/vendor/ln-starter/components/ln/`.
