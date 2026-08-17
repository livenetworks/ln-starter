<?php

namespace LiveNetworks\LnStarter\Console;

use Illuminate\Console\Command;
use LiveNetworks\LnStarter\Support\AuthV2Configuration;
use RuntimeException;

class CheckAuthV2ReadinessCommand extends Command
{
    protected $signature = 'ln-starter:auth-v2-readiness';

    protected $description = 'Validate auth-v2 runtime settings and active pepper references';

    public function handle(AuthV2Configuration $configuration): int
    {
        try {
            $configuration->validate(true);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->components->info('Auth v2 readiness checks passed.');

        return self::SUCCESS;
    }
}
