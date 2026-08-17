<?php

namespace LiveNetworks\LnStarter\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LiveNetworks\LnStarter\Models\MagicLoginAttempt;
use LiveNetworks\LnStarter\Security\ReasonCode;
use LiveNetworks\LnStarter\Security\SecurityEventName;
use RuntimeException;
use Throwable;

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

        if (app()->environment('production')) {
            // Single-use consumption is atomic only because the state machine
            // takes a row lock inside a transaction. Checked last so cheaper
            // misconfiguration errors surface first. The storage-engine probe
            // needs a query, so it runs only on the deep (non-boot) path.
            $this->validateRowLockingDatabase($checkActivePepperReferences);
        }
    }

    /**
     * Reject databases that cannot make the pending-to-consumed transition atomic.
     *
     * `lockForUpdate()` is silently a no-op on SQLite and on MyISAM tables, which
     * would let two concurrent proofs both observe a pending attempt and both
     * authenticate — breaking the single-use invariant without any error.
     */
    private function validateRowLockingDatabase(bool $inspectStorageEngine): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            throw new RuntimeException(
                'LN-Starter auth v2 requires a database with transactional row locking in production; SQLite cannot make single-use consumption atomic.'
            );
        }

        if (!$inspectStorageEngine || !in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        // The table is created by migrations, which may not have run yet on a
        // first install. There is nothing to lock until it exists.
        if (!Schema::hasTable('magic_login_attempts')) {
            return;
        }

        self::assertInnoDbTable('magic_login_attempts', config('database.default'));
    }

    /**
     * Fail closed unless MySQL/MariaDB positively reports InnoDB for the table.
     *
     * An unreadable engine is treated exactly like a wrong engine: we cannot
     * prove row locking works, so readiness must not pass. The failure message
     * never includes connection credentials.
     */
    public static function assertInnoDbTable(string $table, ?string $connection = null): void
    {
        try {
            // Must run on the SAME connection the table lives on. Using the
            // default connection would happily inspect a different database
            // and report a pass for a table it never looked at.
            $row = DB::connection($connection)->selectOne(
                'select engine as ln_engine from information_schema.tables'
                . ' where table_schema = database() and table_name = ?',
                [$table]
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(sprintf(
                'LN-Starter could not determine the storage engine of %s; readiness fails closed. (%s)',
                $table,
                $exception::class
            ));
        }

        if ($row === null) {
            throw new RuntimeException(sprintf(
                'LN-Starter could not find %s in information_schema; storage engine is unknown and readiness fails closed.',
                $table
            ));
        }

        $engine = ((array) $row)['ln_engine'] ?? null;

        if ($engine === null || trim((string) $engine) === '') {
            throw new RuntimeException(sprintf(
                'LN-Starter read an undefined storage engine for %s; readiness fails closed.',
                $table
            ));
        }

        if (strtoupper(trim((string) $engine)) !== 'INNODB') {
            throw new RuntimeException(sprintf(
                'LN-Starter requires an InnoDB %s table for row locking; found %s.',
                $table,
                $engine
            ));
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
                $this->logger->record(SecurityEventName::READINESS_FAILED, [
                    'reason' => ReasonCode::PepperUnavailable->value,
                    'outcome' => 'error',
                    'pepper_id' => (string) $pepperId,
                ]);

                throw new RuntimeException(
                    'LN-Starter auth v2 has an active attempt whose pepper is unavailable.',
                );
            }
        }
    }
}
