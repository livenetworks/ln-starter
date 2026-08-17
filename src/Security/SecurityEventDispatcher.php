<?php

namespace LiveNetworks\LnStarter\Security;

use Illuminate\Support\Facades\Log;
use LiveNetworks\LnStarter\Contracts\SecurityAuditSink;
use Throwable;

/**
 * Builds the canonical envelope and fans it out to every registered sink.
 *
 * Failure policy (ADR 0002): auditing is fail-open with respect to auth
 * availability. A sink that throws is caught, reported to a fallback channel,
 * and announced as a minimal `security.audit.sink_failed` event. It never
 * propagates into the authentication flow and never rolls a transaction back.
 */
class SecurityEventDispatcher
{
    /** @var list<SecurityAuditSink> */
    private array $sinks = [];

    /**
     * Re-entry guard. While reporting a sink failure we must not attempt to
     * dispatch through the same failing sinks again, or a single broken sink
     * becomes an unbounded failure storm.
     */
    private bool $reportingFailure = false;

    public function __construct(
        private readonly ContextSanitizer $sanitizer,
        private readonly RequestContext $requestContext,
    ) {
    }

    public function extend(SecurityAuditSink $sink): void
    {
        $this->sinks[] = $sink;
    }

    /** @return list<SecurityAuditSink> */
    public function sinks(): array
    {
        return $this->sinks;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function dispatch(
        string $eventName,
        Severity $severity,
        Outcome $outcome,
        ?ReasonCode $reasonCode = null,
        array $context = [],
        ?string $principalKey = null,
        ?string $attemptId = null,
        ?float $durationMs = null,
        ?string $authMethod = null,
        ?string $guard = null,
        ?int $statusCode = null,
    ): ?SecurityEvent {
        if (!config('ln-starter.logging.enabled', true)) {
            return null;
        }

        try {
            $event = new SecurityEvent(
                eventId: SecurityEvent::newEventId(),
                eventName: $eventName,
                occurredAt: SecurityEvent::now(),
                severity: $severity,
                outcome: $outcome,
                reasonCode: $reasonCode,
                requestId: $this->requestContext->ensureStarted(),
                correlationId: $this->requestContext->correlationId(),
                environment: (string) config('app.env', 'unknown'),
                application: (string) config('app.name', 'unknown'),
                route: $this->requestContext->routeTemplate(),
                httpMethod: $this->requestContext->method(),
                statusCode: $statusCode,
                authMethod: $authMethod,
                guard: $guard,
                principalKey: $principalKey,
                attemptId: $attemptId,
                // Negative durations would mean a non-monotonic clock; clamp
                // rather than emit nonsense.
                durationMs: $durationMs === null ? null : max(0.0, $durationMs),
                context: $this->sanitizer->sanitize($context),
            );
        } catch (Throwable $exception) {
            $this->fallback('Failed to build a security event.', [
                'event_name' => $eventName,
                'throwable_class' => $exception::class,
            ]);

            return null;
        }

        $this->write($event);

        return $event;
    }

    private function write(SecurityEvent $event): void
    {
        foreach ($this->sinks as $sink) {
            try {
                $sink->write($event);
            } catch (Throwable $exception) {
                $this->reportSinkFailure($sink, $exception);
            }
        }
    }

    private function reportSinkFailure(SecurityAuditSink $sink, Throwable $exception): void
    {
        // Never include the original payload or the throwable message: both can
        // carry the very data the sink was supposed to have sanitized away.
        $detail = [
            'sink' => $sink->name(),
            'throwable_class' => $exception::class,
        ];

        $this->fallback('Security audit sink failed.', $detail);

        if ($this->reportingFailure) {
            return;
        }

        $this->reportingFailure = true;

        try {
            $failureEvent = new SecurityEvent(
                eventId: SecurityEvent::newEventId(),
                eventName: SecurityEventName::SINK_FAILED,
                occurredAt: SecurityEvent::now(),
                severity: Severity::Error,
                outcome: Outcome::Error,
                reasonCode: ReasonCode::SinkFailure,
                requestId: $this->requestContext->ensureStarted(),
                correlationId: $this->requestContext->correlationId(),
                environment: (string) config('app.env', 'unknown'),
                application: (string) config('app.name', 'unknown'),
                context: $this->sanitizer->sanitize($detail),
            );

            foreach ($this->sinks as $candidate) {
                // Skip the sink we already know is broken.
                if ($candidate->name() === $sink->name()) {
                    continue;
                }

                try {
                    $candidate->write($failureEvent);
                } catch (Throwable) {
                    // Swallowed on purpose: the fallback channel above already
                    // recorded that auditing is degraded.
                }
            }
        } finally {
            $this->reportingFailure = false;
        }
    }

    /**
     * Last-resort channel. Uses the framework logger directly so it stays
     * available when the configured sinks are the thing that is broken.
     *
     * @param array<string, mixed> $context
     */
    private function fallback(string $message, array $context): void
    {
        try {
            $channel = config('ln-starter.logging.fallback_channel');
            $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();
            $logger->error($message, $context);
        } catch (Throwable) {
            // Nothing left to try; losing the notice must not break the request.
        }
    }
}
