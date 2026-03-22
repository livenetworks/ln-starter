# Blade Components

## Overview

LN-Starter ships two Blade components — `<x-ln.toast />` and `<x-ln.modal />`. They are registered automatically when the package is installed (no publish needed) and are available in any Blade view.

## Toast — `<x-ln.toast />`

Renders a toast notification container that displays session flash messages (`ok` for success, `$errors` for validation errors). Designed to work with the `Message` DTO and the dual-mode response system.

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
| `class` | string | `ln-toast ln-toast--top-right` | CSS classes (controls position) |
| `timeout` | int | `6000` | Auto-dismiss timeout in milliseconds |
| `max` | int | `5` | Maximum number of visible toasts |

### Position variants

Change position via the `class` parameter:

```blade
<x-ln.toast class="ln-toast ln-toast--top-right" />
<x-ln.toast class="ln-toast ln-toast--top-left" />
<x-ln.toast class="ln-toast ln-toast--bottom-right" />
<x-ln.toast class="ln-toast ln-toast--bottom-left" />
```

### How it works

1. **Success messages** — reads `session('ok')`. Set via `redirect()->with('ok', 'Done!')` or session flash
2. **Error messages** — reads Laravel's `$errors` bag, deduplicates, and renders each as an error toast
3. **Auto-dismiss** — toasts disappear after `timeout` ms
4. **Max limit** — oldest toasts are removed when the `max` count is exceeded

### Server-side example

```php
// In a controller — flash a success message
return redirect()->route('members.index')->with('ok', 'Member created.');

// Validation errors are automatic via Laravel's validator
$request->validate(['email' => 'required|email']);
// If validation fails, $errors is populated and toasts render automatically
```

### Data attributes

The component emits these `data-*` attributes for JS integration:

| Attribute | On | Purpose |
|-----------|------|---------|
| `data-ln-toast` | container | Identifies the toast container |
| `data-ln-toast-timeout` | container | Timeout value for JS auto-dismiss |
| `data-ln-toast-max` | container | Max visible toasts for JS |
| `data-ln-toast-item` | each toast | Identifies individual toast items |
| `data-type` | each toast | Toast type: `success` or `error` |

## Modal — `<x-ln.modal />`

Renders a modal dialog with a form wrapper. Suitable for confirmation dialogs, inline forms, and AJAX-submitted actions.

### Usage

```blade
<x-ln.modal id="delete-member" title="Delete member?" submitText="Delete" action="/members/5" method="POST">
    <p>Are you sure you want to delete this member?</p>
    @method('DELETE')
</x-ln.modal>
```

### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | string | `''` | Modal element ID (required for targeting) |
| `title` | string | `''` | Header title text |
| `submitText` | string | `Submit` | Submit button label |
| `action` | string\|null | `null` | Form action URL. When `null`, no `action`/`method` attributes are rendered |
| `method` | string | `POST` | HTTP method (`POST` or `GET`). For PUT/PATCH/DELETE, use `POST` with `@method()` |

### Structure

The modal renders this structure:

```html
<div id="{id}" class="ln-modal" role="dialog" aria-modal="true">
    <form action="{action}" method="POST" class="ln-modal__content" data-ln-ajax>
        <header class="ln-modal__header">
            <h2 class="ln-modal__title">{title}</h2>
            <button data-ln-modal-close type="button" class="ln-modal__close">&times;</button>
        </header>
        <main class="ln-modal__body">
            {slot — your content}
        </main>
        <footer class="ln-modal__footer">
            <button type="button" data-ln-modal-close>Cancel</button>
            <button type="submit">{submitText}</button>
        </footer>
    </form>
</div>
```

### AJAX submission

The form has `data-ln-ajax`, which means the frontend JS (ln-acme) intercepts the form submission and sends it as an AJAX request. The response flows through the dual-mode system.

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
    <div class="form-group">
        <label for="note-body">{{ __('Note') }}</label>
        <textarea name="body" id="note-body" required></textarea>
    </div>
</x-ln.modal>
```

#### Modal without form action

When `action` is `null`, the form tag renders without `action` and `method` attributes — useful for JS-only modals:

```blade
<x-ln.modal id="preview" title="Preview">
    <div id="preview-content"></div>
</x-ln.modal>
```

### Data attributes for JS

| Attribute | On | Purpose |
|-----------|------|---------|
| `data-ln-modal-close` | close/cancel buttons | JS hook to close the modal |
| `data-ln-ajax` | form | JS hook for AJAX form submission |

## CSS dependency

Both components use CSS classes from the `ln-acme` component library (`ln-toast`, `ln-modal`). Ensure `ln-acme` is installed via npm and its styles are imported in your SCSS.

## No publish needed

The components are registered via `Blade::component()` in the service provider and load their templates from the package. They work immediately after `composer require`.

To override a component's template, publish and place your version at:

```
resources/views/vendor/ln-starter/components/ln/toast.blade.php
resources/views/vendor/ln-starter/components/ln/modal.blade.php
```
