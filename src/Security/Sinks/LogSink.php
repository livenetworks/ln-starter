<?php

namespace LiveNetworks\LnStarter\Security\Sinks;

use Illuminate\Support\Facades\Log;
use LiveNetworks\LnStarter\Contracts\SecurityAuditSink;
use LiveNetworks\LnStarter\Security\SecurityEvent;

/**
 * Default sink: writes the envelope to a Laravel log channel as structured
 * context.
 *
 * The message is the event name and nothing else — the payload travels in the
 * context array, never interpolated into the string. That keeps the output
 * machine-parseable for a SIEM and makes log injection through user-controlled
 * values impossible.
 */
class LogSink implements SecurityAuditSink
{
    public function name(): string
    {
        return 'log';
    }

    public function write(SecurityEvent $event): void
    {
        $channel = config('ln-starter.logging.channel');
        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();

        $logger->log(
            $event->severity->toPsrLevel(),
            $event->eventName,
            $event->toArray()
        );
    }
}
