# Read/write model separation

## Principle

Separate Eloquent models into two base classes:

- **LNReadModel** — for database views, materialized views, and read-only tables
- **LNWriteModel** — for writable tables

This enforces the intent at the model level: a read model cannot accidentally write to the database.

## LNReadModel

```php
abstract class LNReadModel extends Model
{
    public $timestamps = false;
    public $incrementing = false;

    public function create(array $attributes = []) { return false; }
    public function update(array $attributes = [], array $options = []) { return false; }
    public function delete() { return false; }
    public function save(array $options = []) { return false; }
}
```

All write operations are blocked by returning `false`. This is a safety net — the database view itself would reject writes, but this catches mistakes at the application level before they hit the DB.

### When to use

- Database views (`CREATE VIEW v_members AS ...`)
- Materialized views
- Read-only reference tables (countries, currencies)
- Denormalized reporting tables

### Example

```php
class VMember extends LNReadModel
{
    protected $table = 'v_members';

    // Typically maps to a view that joins members, users, lodges, etc.
    // No $fillable needed — writes are blocked
}
```

## LNWriteModel

```php
abstract class LNWriteModel extends Model
{
    public $timestamps = false;
}
```

Minimal base — just disables timestamps by default. Override `$timestamps = true` in child classes that need them.

### When to use

- Standard database tables
- Any model that needs to create, update, or delete records

### Example

```php
class Member extends LNWriteModel
{
    protected $table = 'members';
    protected $fillable = ['name', 'email', 'lodge_id'];

    // Override if this table has created_at/updated_at
    // public $timestamps = true;
}
```

## Naming convention

| Type | Prefix | Example |
|---|---|---|
| Read model (view) | `V` | `VMember`, `VTransaction`, `VReport` |
| Write model (table) | none | `Member`, `Transaction`, `Report` |

The `V` prefix signals "this is a view" — developers immediately know not to expect write operations.

## Database view example

```sql
CREATE VIEW v_members AS
SELECT
    m.id,
    m.name,
    m.email,
    m.lodge_id,
    l.name AS lodge_name,
    u.id AS user_id,
    u.email AS user_email
FROM members m
LEFT JOIN lodges l ON l.id = m.lodge_id
LEFT JOIN users u ON u.id = m.user_id;
```

## When to use which

**Use LNReadModel when:**
- The underlying source is a DB view
- You only need SELECT queries
- You want to prevent accidental writes

**Use LNWriteModel when:**
- The underlying source is a real table
- You need INSERT/UPDATE/DELETE operations
- You need Eloquent relationships for writes
