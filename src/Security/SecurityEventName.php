<?php

namespace LiveNetworks\LnStarter\Security;

/**
 * Canonical event names emitted by this package.
 *
 * A class of constants rather than an enum: consuming applications emit their
 * own event names through the same pipeline, so the name field must stay an
 * open string. These constants pin the package's own vocabulary and give
 * static analysis something to check against.
 *
 * @see docs/security-logging.md for the documented catalog.
 */
final class SecurityEventName
{
    // Magic-login request lifecycle
    public const REQUEST_RECEIVED = 'auth.magic.request.received';
    public const REQUEST_ACCEPTED = 'auth.magic.request.accepted';
    public const REQUEST_REJECTED = 'auth.magic.request.rejected';
    public const RATE_LIMITED = 'auth.magic.rate_limited';

    // Delivery
    public const DELIVERY_QUEUED = 'auth.magic.delivery.queued';
    public const DELIVERY_SUCCEEDED = 'auth.magic.delivery.succeeded';
    public const DELIVERY_FAILED = 'auth.magic.delivery.failed';

    // Proof verification
    public const PROOF_ACCEPTED = 'auth.magic.proof.accepted';
    public const PROOF_REJECTED = 'auth.magic.proof.rejected';
    public const PROOF_REPLAYED = 'auth.magic.proof.replayed';
    public const PROOF_EXPIRED = 'auth.magic.proof.expired';
    public const CODE_LOCKED = 'auth.magic.code.locked';
    public const LINK_OPENED = 'auth.magic.link.opened';
    public const SIBLINGS_REVOKED = 'auth.magic.attempts.revoked';

    // Session lifecycle
    public const SESSION_CREATED = 'auth.session.created';
    public const SESSION_TERMINATED = 'auth.session.terminated';
    public const SESSION_TERMINATE_NOOP = 'auth.session.terminate_noop';

    // Infrastructure
    public const READINESS_FAILED = 'security.readiness.failed';
    public const SINK_FAILED = 'security.audit.sink_failed';

    /**
     * Every name this package emits. Used by the documentation test to prove
     * the catalog and the code cannot drift apart.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }

    private function __construct()
    {
    }
}
