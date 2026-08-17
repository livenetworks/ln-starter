<?php

namespace LiveNetworks\LnStarter\Security;

/**
 * Event severity, mapped onto PSR-3 levels by the log sink.
 */
enum Severity: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    /**
     * PSR-3 level name. The enum values already match PSR-3, but going through
     * a method keeps the mapping explicit if the vocabularies ever diverge.
     */
    public function toPsrLevel(): string
    {
        return $this->value;
    }
}
