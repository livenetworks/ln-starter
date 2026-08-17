<?php

namespace LiveNetworks\LnStarter\Security\Sinks;

use Illuminate\Support\Facades\DB;
use LiveNetworks\LnStarter\Contracts\SecurityAuditSink;
use LiveNetworks\LnStarter\Security\SecurityEvent;

/**
 * Opt-in durable audit trail.
 *
 * Disabled by default so that fresh installs and upgrades gain a table only
 * when the operator asks for one. Writes go to a dedicated table that is never
 * shared with the auth attempt tables.
 *
 * Only the already-sanitized envelope is persisted; the context column holds
 * the sanitizer's output encoded as JSON.
 */
class DatabaseSink implements SecurityAuditSink
{
    public const TABLE = 'ln_security_audit_events';

    public function name(): string
    {
        return 'database';
    }

    public function write(SecurityEvent $event): void
    {
        $payload = $event->toArray();
        $context = $payload['context'] ?? [];

        DB::connection($this->connection())->table(self::TABLE)->insert([
            'id' => $event->eventId,
            'event_name' => $event->eventName,
            'schema_version' => $event->schemaVersion,
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s'),
            'severity' => $event->severity->value,
            'outcome' => $event->outcome->value,
            'reason_code' => $event->reasonCode?->value,
            'request_id' => $event->requestId,
            'correlation_id' => $event->correlationId,
            'principal_key' => $event->principalKey,
            'attempt_id' => $event->attemptId,
            'route' => $event->route,
            'http_method' => $event->httpMethod,
            'status_code' => $event->statusCode,
            'auth_method' => $event->authMethod,
            'duration_ms' => $event->durationMs,
            'context' => $context === [] ? null : json_encode(
                $context,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ),
            'created_at' => now(),
        ]);
    }

    public function connection(): ?string
    {
        $connection = config('ln-starter.logging.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
