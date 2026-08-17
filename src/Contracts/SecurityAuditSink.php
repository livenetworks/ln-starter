<?php

namespace LiveNetworks\LnStarter\Contracts;

use LiveNetworks\LnStarter\Security\SecurityEvent;

/**
 * A destination for security events.
 *
 * Consuming applications implement this and register the instance with
 * SecurityEventDispatcher::extend() from a service provider — no package code
 * needs to change.
 *
 * Implementations receive an already-sanitized event. They must not re-enter
 * the dispatcher, and they may throw: the dispatcher isolates failures so a
 * broken sink can never break an authentication flow.
 */
interface SecurityAuditSink
{
    /**
     * Stable identifier used in sink-failure reporting. Keep it short and
     * free of secrets — it is written to logs.
     */
    public function name(): string;

    public function write(SecurityEvent $event): void;
}
