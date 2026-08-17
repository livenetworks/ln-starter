<?php

namespace LiveNetworks\LnStarter\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use LiveNetworks\LnStarter\Models\MagicLoginAttempt;
use RuntimeException;

class AuthV2Configuration
{
    public function __construct(
        private readonly MagicLoginProofs $proofs,
        private readonly SecurityEventLogger $logger,
    ) {}

    public function validate(bool $checkActivePepperReferences = false): void
    {
        $this->proofs->assertConfigured();

        if (app()->environment('production')) {
            if (config('queue.default') === 'sync') {
                throw new RuntimeException('LN-Starter auth v2 requires a non-sync queue in production.');
            }

            if (in_array(config('session.driver'), ['array', 'cookie'], true)) {
                throw new RuntimeException('LN-Starter auth v2 requires a lock-capable session backend in production.');
            }

            $lockStore = Cache::store(config('session.block_store'))->getStore();
            if (
                !$lockStore instanceof LockProvider
                || $lockStore instanceof ArrayStore
                || $lockStore instanceof NullStore
            ) {
                throw new RuntimeException('LN-Starter auth v2 requires a shared lock-capable session block cache in production.');
            }
        }

        if ($checkActivePepperReferences) {
            $this->validateActivePepperReferences();
        }
    }

    private function validateActivePepperReferences(): void
    {
        if (!Schema::hasTable('magic_login_attempts')) {
            return;
        }

        $pepperIds = MagicLoginAttempt::query()
            ->where('status', MagicLoginAttempt::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->distinct()
            ->pluck('pepper_id');

        foreach ($pepperIds as $pepperId) {
            try {
                $this->proofs->pepper((string) $pepperId);
            } catch (RuntimeException) {
                $this->logger->record('auth.magic.pepper.unavailable', [
                    'outcome' => 'unavailable',
                ]);

                throw new RuntimeException(
                    'LN-Starter auth v2 has an active attempt whose pepper is unavailable.',
                );
            }
        }
    }
}
