<?php

namespace LiveNetworks\LnStarter\Console;

use Illuminate\Console\Command;
use LiveNetworks\LnStarter\Models\MagicLinkToken;

class CleanupMagicLinkTokensCommand extends Command
{
    protected $signature = 'magic-link-tokens:cleanup
                            {--hours=24 : Delete tokens older than this many hours}';

    protected $description = 'Delete expired and used magic link tokens';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');

        $deleted = MagicLinkToken::where('expires_at', '<', now()->subHours($hours))
            ->orWhere(function ($query) {
                $query->where('approved', true);
            })
            ->delete();

        $this->components->info("Deleted {$deleted} magic link token(s).");

        return self::SUCCESS;
    }
}
