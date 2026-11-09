# Message DTO

## Purpose

`Message` is a simple data transfer object for communicating status information from the controller to the client. It works identically in all response modes — browser, AJAX, and API.

## Structure

```php
class Message implements \JsonSerializable
{
    public function __construct(
        public string $type,       // success | error | warning | info
        public string $title = '',
        public string $body = '',
        public array $data = []
    ) {}
}
```

## Controller Helpers (on `LNController`)

`LNController` provides convenient helper methods to create and return `Message` DTOs without instantiating them manually:

### Toast Factories

```php
// Factory methods returning Message DTO
$msg = $this->toastSuccess('Member created successfully.');
$msg = $this->toastError('Unable to process payment.', 'Payment Failed');
$msg = $this->toastWarning('Subscription expires in 3 days.');
$msg = $this->toastInfo('New features available.');
$msg = $this->toast('custom_type', 'Body text', 'Title');
```

### Response & Redirect Helpers

```php
// Respond with view + toast message (works across full page, AJAX, and data modes)
return $this->view('members.index')
    ->respondWithToast($members, 'Members loaded successfully.');

// Redirect back with toast notification in session
return $this->backWithToast('Status updated successfully.');
return $this->backWithToast('Invalid credentials provided.', 'error');

// Redirect to named route with toast notification in session
return $this->redirectWithToast('members.index', 'Member was removed.');
return $this->redirectWithToast('members.show', $msg, ['member' => $member]);
```

## Usage

### In controllers

```php
// Direct instantiation
return $this->view('members.index')
    ->respondWith($members, new Message('success', 'Done', 'Members loaded.'));

// Error with extra data
return $this->view('members.form')
    ->respondWith($formData, new Message(
        type: 'error',
        title: 'Validation failed',
        body: 'Please fix the highlighted fields.',
        data: ['field_errors' => $errors]
    ));

// No message (just data)
return $this->view('members.index')
    ->respondWith($members);
```

### JSON output

```json
{
    "type": "success",
    "title": "Created",
    "body": "Member was created successfully.",
    "data": { "id": 42 }
}
```

### In Blade templates

```blade
@if($message)
    <div class="alert alert--{{ $message->type }}">
        <strong>{{ $message->title }}</strong>
        <p>{{ $message->body }}</p>
    </div>
@endif
```

### In frontend JS (AJAX)

```javascript
fetch('/members', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
        if (data.message) {
            // Use with ln-acme toast component or similar
            showToast(data.message.type, data.message.title, data.message.body);
        }
    });
```

## Message types

| Type | Use for |
|---|---|
| `success` | Successful operations (create, update, delete) |
| `error` | Failed operations, business rule violations |
| `warning` | Non-blocking issues, deprecation notices |
| `info` | Neutral information, status updates |

## Integration with BusinessException

When catching a `BusinessException`, convert it to a Message:

```php
try {
    $this->processPayment($data);
} catch (BusinessException $e) {
    return $this->view('payments.form')
        ->respondWith($formData, new Message(
            type: 'error',
            title: $e->title,
            body: $e->getMessage()
        ));
}
```
