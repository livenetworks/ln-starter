<?php

namespace LiveNetworks\LnStarter\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SecurityEventLogger
{
    private const ALLOWED_CONTEXT = [
        'attempt_id',
        'user_id',
        'request_id',
        'outcome',
        'reason',
        'duration_ms',
        'pepper_id',
        'consumed_via',
        'count',
    ];

    public function requestId(): string
    {
        if (!app()->bound('request')) {
            return (string) Str::uuid();
        }

        $request = request();
        $existing = $request->attributes->get('ln_starter.request_id');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $id = (string) Str::uuid();
        $request->attributes->set('ln_starter.request_id', $id);

        return $id;
    }

    public function record(string $event, array $context = []): void
    {
        if (!config('ln-starter.logging.enabled', true)) {
            return;
        }

        $safe = array_intersect_key($context, array_flip(self::ALLOWED_CONTEXT));
        $safe['request_id'] ??= $this->requestId();

        if (app()->bound('request')) {
            $request = request();
            $safe['route'] = $request->route()?->getName()
                ?? $request->route()?->uri()
                ?? 'unmatched';
            $safe['method'] = $request->method();
        }

        $channel = config('ln-starter.logging.channel');
        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();
        $logger->info($event, $safe);
    }
}
