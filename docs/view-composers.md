# View composers

## Overview

View composers enrich the response data before a Blade view renders. They follow the same pattern as `respondWith()` — working with the `$response['content']` array that the controller populates.

## The problem

Controllers set the primary data via `respondWith()`. But views often need secondary data (dropdown options, related records, cached lookups) that the controller shouldn't care about. Without composers, controllers accumulate data-fetching logic that has nothing to do with the action being performed.

## LNViewComposer

The base class extracts `$response['content']` from the view, passes it to your `enrich()` method by reference, and writes it back. You only implement `enrich()`.

```php
use LiveNetworks\LnStarter\View\LNViewComposer;
use Illuminate\View\View;

class ProductFormComposer extends LNViewComposer
{
    public function enrich(array &$content, View $view): void
    {
        $content['categories'] = Cache::remember('categories.all', INF,
            fn() => Category::orderBy('name')->get()
        );
    }
}
```

### What the base class does

```php
// You don't write this — LNViewComposer handles it:
public function compose(View $view): void
{
    $response = $view->getData()['response'] ?? ['content' => []];
    $this->enrich($response['content'], $view);
    $view->with('response', $response);
}
```

## Common patterns

### Static lookup data (cached)

For data that rarely changes (grades, categories, countries), cache permanently and bust manually:

```php
public function enrich(array &$content, View $view): void
{
    $content['grades'] = Cache::remember('grades.ordered', INF,
        fn() => Grade::orderBy('sequence')->get()
    );

    $content['lodges'] = Cache::remember('lodges.all', INF,
        fn() => Lodge::all()
    );
}
```

### Context-dependent data

When the composer needs data from the controller's response (e.g., fetching related records for a specific entity):

```php
public function enrich(array &$content, View $view): void
{
    $lodgeId = $content['lodge']->id ?? null;

    if ($lodgeId) {
        $content['members'] = VMember::where('lodge_id', $lodgeId)->get();
    }
}
```

### Permission-filtered data

When visibility depends on the authenticated user's permissions:

```php
public function enrich(array &$content, View $view): void
{
    $memberId = $content['member']->id ?? null;
    if (!$memberId) return;

    $query = VLecture::where('member_id', $memberId);

    $user = auth()->user();
    if ($user && !$user->hasPermission('lectures.manage')) {
        $query->where('grade', '<=', $user->member->grade ?? 0);
    }

    $content['lectures'] = $query->orderBy('read', 'desc')->get();
}
```

## Registration

Register composers in a service provider:

```php
use Illuminate\Support\Facades\View;

class ViewComposerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::composer('products.form', ProductFormComposer::class);
        View::composer('products.index', ProductsIndexComposer::class);

        // Same composer for multiple views
        View::composer(['orders.form', 'orders.edit'], OrderFormComposer::class);

        // Wildcard — all views in a directory
        View::composer('members.*', MemberContextComposer::class);
    }
}
```

## Flow

```
1. Controller: $this->view('products.form')->respondWith($product)
2. Laravel resolves the Blade view
3. View composer fires: enrich($response['content'])
   └─ Adds 'categories', 'brands', etc. to $content
4. Blade template renders with complete data:
   └─ $response['content']['product']     ← from controller
   └─ $response['content']['categories']  ← from composer
   └─ $response['content']['brands']      ← from composer
```

## Rules

- Composers NEVER replace controller data — they only ADD to `$content`
- Composers NEVER redirect or abort — that's the controller's job
- Composers SHOULD use caching for static data
- Composers SHOULD handle missing context gracefully (null checks)
- One composer per view (or small view group) — don't make god-composers
