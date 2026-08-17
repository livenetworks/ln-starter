<?php

namespace LiveNetworks\LnStarter\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model over the durable audit trail.
 *
 * Rows are written by the DatabaseSink through the query builder, not through
 * Eloquent, so nothing in the write path depends on this class. It exists for
 * applications that want to query their audit history.
 */
class SecurityAuditEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'ln_security_audit_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'schema_version' => 'integer',
            'status_code' => 'integer',
            'duration_ms' => 'float',
            'context' => 'array',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('ln-starter.logging.database.connection') ?: parent::getConnectionName();
    }
}
