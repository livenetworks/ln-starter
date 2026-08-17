<?php

namespace LiveNetworks\LnStarter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CutoverAuthV2Command extends Command
{
    protected $signature = 'ln-starter:auth-v2-cutover
                            {--force : Confirm invalidation of pending auth-v1 proofs}';

    protected $description = 'Invalidate pending legacy magic-link proofs before enabling auth v2';

    public function handle(): int
    {
        if (!$this->option('force')) {
            $this->components->error('Re-run with --force to invalidate pending auth-v1 proofs.');
            return self::FAILURE;
        }

        if (!Schema::hasTable('magic_link_tokens')) {
            $this->components->info('No legacy magic_link_tokens table exists.');
            return self::SUCCESS;
        }

        $count = DB::table('magic_link_tokens')
            ->where('approved', false)
            ->delete();

        $this->components->info("Invalidated {$count} pending auth-v1 proof(s). This cannot be undone.");

        return self::SUCCESS;
    }
}
