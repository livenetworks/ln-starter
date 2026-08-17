<?php

namespace LiveNetworks\LnStarter\Console;

use Illuminate\Console\Command;
use LiveNetworks\LnStarter\Support\AuthV2UpgradeAudit;

class AuditAuthV2Command extends Command
{
    protected $signature = 'ln-starter:auth-v2-audit';

    protected $description = 'Detect published LN-Starter auth v1 views that must be ported to auth v2';

    public function handle(AuthV2UpgradeAudit $audit): int
    {
        $findings = $audit->legacyPublishedViews();

        if ($findings === []) {
            $this->components->info('No legacy LN-Starter auth view overrides detected.');
            return self::SUCCESS;
        }

        $this->components->error('Legacy auth view overrides must be ported or republished before auth v2 is enabled:');
        foreach ($findings as $path) {
            $this->line('  - ' . $path);
        }

        return self::FAILURE;
    }
}
