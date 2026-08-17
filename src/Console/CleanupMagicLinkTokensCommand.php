<?php

namespace LiveNetworks\LnStarter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use LiveNetworks\LnStarter\Models\MagicLinkToken;
use LiveNetworks\LnStarter\Models\MagicLoginAttempt;

class CleanupMagicLinkTokensCommand extends Command
{
    protected $signature = 'magic-login-attempts:cleanup
                            {--hours=24 : Retain terminal/expired attempts for this many hours}';

    protected $aliases = ['magic-link-tokens:cleanup'];

    protected $description = 'Delete retained terminal/expired magic login attempts and legacy tokens';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);
        $deletedAttempts = 0;
        $deletedLegacy = 0;

        if (Schema::hasTable('magic_login_attempts')) {
            $deletedAttempts = MagicLoginAttempt::query()
                ->where('updated_at', '<', $cutoff)
                ->where(function ($query) {
                    $query->whereIn('status', [
                        MagicLoginAttempt::STATUS_CONSUMED,
                        MagicLoginAttempt::STATUS_REVOKED,
                        MagicLoginAttempt::STATUS_EXPIRED,
                    ])->orWhere('expires_at', '<=', now());
                })
                ->delete();
        }

        if (Schema::hasTable('magic_link_tokens')) {
            $deletedLegacy = MagicLinkToken::query()
                ->where('updated_at', '<', $cutoff)
                ->where(function ($query) {
                    $query->where('approved', true)
                        ->orWhere('expires_at', '<=', now());
                })
                ->delete();
        }

        $this->components->info(
            "Deleted {$deletedAttempts} auth-v2 attempt(s) and {$deletedLegacy} legacy token(s)."
        );

        return self::SUCCESS;
    }
}
