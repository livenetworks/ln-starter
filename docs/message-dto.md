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

## Usage

### In controllers

```php
// Simple success
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
