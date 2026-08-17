<?php

namespace LiveNetworks\LnStarter\Console;

use Illuminate\Console\Command;
use LiveNetworks\LnStarter\Support\AuthV2Configuration;
use LiveNetworks\LnStarter\Support\SecurityObservabilityConfiguration;
use RuntimeException;

class CheckAuthV2ReadinessCommand extends Command
{
    protected $signature = 'ln-starter:auth-v2-readiness';

    protected $description = 'Validate auth-v2 and security-logging runtime settings';

    public function handle(
        AuthV2Configuration $configuration,
        SecurityObservabilityConfiguration $observability,
    ): int {
        // Deep mode: both validators may touch the database here, which is
        // exactly what a readiness command is for.
        try {
            $observability->validate(true);
        } catch (RuntimeException $exception) {
            $this->components->error('Security logging readiness failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if (config('ln-starter.auth.enabled', false)) {
            try {
                $configuration->validate(true);
            } catch (RuntimeException $exception) {
                $this->components->error('Auth v2 readiness failed: ' . $exception->getMessage());

                return self::FAILURE;
            }
        }

        // Secret-free by construction: summary() never returns key material.
        foreach ($observability->summary() as $label => $value) {
            $this->components->twoColumnDetail($label, $value);
        }

        $this->components->info('Readiness checks passed.');

        return self::SUCCESS;
    }
}
