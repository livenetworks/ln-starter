<?php

namespace LiveNetworks\LnStarter\Support;

use LiveNetworks\LnStarter\Security\Outcome;
use LiveNetworks\LnStarter\Security\Pseudonymizer;
use LiveNetworks\LnStarter\Security\ReasonCode;
use LiveNetworks\LnStarter\Security\RequestContext;
use LiveNetworks\LnStarter\Security\SecurityEvent;
use LiveNetworks\LnStarter\Security\SecurityEventDispatcher;
use LiveNetworks\LnStarter\Security\Severity;

/**
 * Public entry point for recording security events.
 *
 * This is the API both the package and consuming applications use. It owns the
 * ergonomics (severity inference, pseudonymization, backwards-compatible
 * context shape) and delegates envelope construction and fan-out to
 * SecurityEventDispatcher.
 *
 * @see \LiveNetworks\LnStarter\Contracts\SecurityAuditSink to add a destination.
 */
class SecurityEventLogger
{
    public function __construct(
        private readonly SecurityEventDispatcher $dispatcher,
        private readonly Pseudonymizer $pseudonymizer,
        private readonly RequestContext $context,
    ) {
    }

    /**
     * Correlation identity of the current request or job.
     */
    public function requestId(): string
    {
        return $this->context->requestId()
            ?? $this->context->startRequest(null);
    }

    public function correlationId(): ?string
    {
        return $this->context->correlationId();
    }

    /**
     * Pseudonymize an actor reference. Returns `<version>:<hmac>` or null.
     */
    public function principalKey(?string $value): ?string
    {
        return $this->pseudonymizer->tryForPurpose('principal', $value);
    }

    /**
     * Full-fidelity emission.
     *
     * @param array<string, mixed> $context Sanitized before it reaches a sink.
     */
    public function event(
        string $eventName,
        Outcome $outcome,
        ?Severity $severity = null,
        ?ReasonCode $reasonCode = null,
        array $context = [],
        ?string $principalKey = null,
        ?string $attemptId = null,
        ?float $durationMs = null,
        ?string $authMethod = null,
        ?string $guard = null,
        ?int $statusCode = null,
    ): ?SecurityEvent {
        return $this->dispatcher->dispatch(
            eventName: $eventName,
            severity: $severity ?? self::severityFor($outcome),
            outcome: $outcome,
            reasonCode: $reasonCode,
            context: $context,
            // Enforced at the boundary rather than trusted: the public API is
            // callable by application code, and a raw email or user ID passed
            // here would otherwise land in every sink.
            principalKey: $this->safePrincipalKey($principalKey),
            attemptId: $attemptId,
            durationMs: $durationMs,
            authMethod: $authMethod,
            guard: $guard,
            statusCode: $statusCode,
        );
    }

    /**
     * Backwards-compatible shape used by the auth v2 call sites.
     *
     * Accepts the loose `['outcome' => 'consumed', 'reason' => '...']` context
     * that predates the typed envelope and promotes it into real fields, so
     * existing callers keep working while emitting the full schema.
     *
     * @param array<string, mixed> $context
     */
    public function record(string $eventName, array $context = []): ?SecurityEvent
    {
        $outcome = self::outcomeFrom($context['outcome'] ?? null);
        $reason = self::reasonFrom($context['reason'] ?? $context['reason_code'] ?? null);

        $attemptId = isset($context['attempt_id']) && is_scalar($context['attempt_id'])
            ? (string) $context['attempt_id']
            : null;

        $duration = isset($context['duration_ms']) && is_numeric($context['duration_ms'])
            ? (float) $context['duration_ms']
            : null;

        // Both are run through safePrincipalKey() downstream, so a caller that
        // puts a raw address in `principal_key` gets it pseudonymized rather
        // than published.
        $principalKey = null;
        if (isset($context['principal_key']) && is_string($context['principal_key'])) {
            $principalKey = $context['principal_key'];
        } elseif (isset($context['user_id']) && is_scalar($context['user_id'])) {
            $principalKey = $this->principalKey('user:' . $context['user_id']);
        }

        // Promoted keys are removed so they are not duplicated in `context`.
        unset(
            $context['outcome'],
            $context['reason'],
            $context['reason_code'],
            $context['attempt_id'],
            $context['duration_ms'],
            $context['principal_key'],
            $context['user_id'],
        );

        return $this->event(
            eventName: $eventName,
            outcome: $outcome,
            reasonCode: $reason,
            context: $context,
            principalKey: $principalKey,
            attemptId: $attemptId,
            durationMs: $duration,
        );
    }

    /**
     * Accept only values this package produced.
     *
     * Anything else — a raw email, a bare user ID, an opaque string from an
     * application — is pseudonymized rather than trusted, so the privacy
     * guarantee holds for consumer code as well as for the built-in flow.
     */
    private function safePrincipalKey(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (Pseudonymizer::isPseudonymKey($value)) {
            return $value;
        }

        return $this->pseudonymizer->tryForPurpose('principal', $value);
    }

    public static function severityFor(Outcome $outcome): Severity
    {
        return match ($outcome) {
            Outcome::Success, Outcome::Pending => Severity::Info,
            Outcome::Rejected, Outcome::Failure => Severity::Warning,
            Outcome::Error => Severity::Error,
        };
    }

    private static function outcomeFrom(mixed $value): Outcome
    {
        if ($value instanceof Outcome) {
            return $value;
        }

        if (!is_string($value)) {
            return Outcome::Pending;
        }

        return match ($value) {
            'success', 'succeeded', 'consumed', 'accepted' => Outcome::Success,
            'rejected', 'expired', 'locked', 'replayed', 'throttled' => Outcome::Rejected,
            'failure', 'failed' => Outcome::Failure,
            'error', 'unavailable' => Outcome::Error,
            default => Outcome::Pending,
        };
    }

    private static function reasonFrom(mixed $value): ?ReasonCode
    {
        if ($value instanceof ReasonCode) {
            return $value;
        }

        // Unknown strings collapse to `unspecified` rather than leaking an
        // arbitrary message into the closed reason-code vocabulary.
        return is_string($value) ? (ReasonCode::tryFrom($value) ?? ReasonCode::Unspecified) : null;
    }
}
