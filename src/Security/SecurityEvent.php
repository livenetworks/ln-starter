<?php

namespace LiveNetworks\LnStarter\Security;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * An immutable security event.
 *
 * Constructed once by the dispatcher with an already-sanitized context, then
 * handed to every sink. Nothing here holds a framework object: sinks receive
 * plain scalars and arrays so they can serialize without side effects.
 */
final class SecurityEvent
{
    /**
     * Envelope contract version. Adding an optional field does not bump this;
     * removing, renaming, or retyping a field does.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * @param array<string, mixed> $context Already sanitized.
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventName,
        public readonly DateTimeImmutable $occurredAt,
        public readonly Severity $severity,
        public readonly Outcome $outcome,
        public readonly ?ReasonCode $reasonCode = null,
        public readonly ?string $requestId = null,
        public readonly ?string $correlationId = null,
        public readonly string $environment = 'unknown',
        public readonly string $application = 'unknown',
        public readonly ?string $route = null,
        public readonly ?string $httpMethod = null,
        public readonly ?int $statusCode = null,
        public readonly ?string $authMethod = null,
        public readonly ?string $guard = null,
        public readonly ?string $principalKey = null,
        public readonly ?string $attemptId = null,
        public readonly ?float $durationMs = null,
        public readonly array $context = [],
        public readonly int $schemaVersion = self::SCHEMA_VERSION,
    ) {
    }

    public static function newEventId(): string
    {
        return (string) Str::ulid();
    }

    public static function now(): DateTimeImmutable
    {
        // Always UTC: audit rows outlive the application's timezone config.
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Flat, serializable envelope. This is the wire format sinks persist.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->eventName,
            'schema_version' => $this->schemaVersion,
            'occurred_at' => $this->occurredAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.vp'),
            'severity' => $this->severity->value,
            'outcome' => $this->outcome->value,
            'reason_code' => $this->reasonCode?->value,
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
            'environment' => $this->environment,
            'application' => $this->application,
            'route' => $this->route,
            'http_method' => $this->httpMethod,
            'status_code' => $this->statusCode,
            'auth_method' => $this->authMethod,
            'guard' => $this->guard,
            'principal_key' => $this->principalKey,
            'attempt_id' => $this->attemptId,
            'duration_ms' => $this->durationMs,
            'context' => $this->context,
        ];
    }
}
