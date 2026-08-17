<?php

namespace LiveNetworks\LnStarter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LiveNetworks\LnStarter\Security\Sinks\DatabaseSink;

/**
 * Retention enforcement for the durable audit trail.
 *
 * Deletion is chunked rather than a single unbounded DELETE: an audit table is
 * exactly the kind of table that grows large, and a full-table delete would
 * hold locks long enough to affect the auth flow it is supposed to observe.
 */
class PruneSecurityAuditEventsCommand extends Command
{
    protected $signature = 'ln-starter:security-audit-prune
                            {--days= : Override the configured retention window}
                            {--chunk=1000 : Rows deleted per statement}
                            {--dry-run : Report what would be deleted without deleting}
                            {--force : Required to delete in production}';

    protected $description = 'Delete security audit events older than the retention window';

    public function handle(): int
    {
        $connection = config('ln-starter.logging.database.connection') ?: null;

        if (!Schema::connection($connection)->hasTable(DatabaseSink::TABLE)) {
            $this->components->error(
                'Table ' . DatabaseSink::TABLE . ' does not exist. Publish and run the audit migration first.'
            );

            return self::FAILURE;
        }

        $days = (int) ($this->option('days') ?? config('ln-starter.logging.database.retention_days', 90));

        if ($days < 1) {
            $this->components->error('Retention window must be at least 1 day.');

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = fn () => DB::connection($connection)
            ->table(DatabaseSink::TABLE)
            ->where('occurred_at', '<', $cutoff);

        $matched = $query()->count();

        $this->components->info(sprintf(
            'Retention %d day(s); cutoff %s; matched %d record(s).',
            $days,
            $cutoff->toDateTimeString(),
            $matched
        ));

        if ($dryRun) {
            $this->components->warn('Dry run: no records were deleted.');

            return self::SUCCESS;
        }

        if ($matched === 0) {
            $this->components->info('Nothing to delete.');

            return self::SUCCESS;
        }

        if (app()->environment('production') && !$this->option('force')) {
            $this->components->error('Refusing to delete in production without --force.');

            return self::FAILURE;
        }

        $deleted = 0;

        // Bounded statements so a large backlog never becomes one long lock.
        // Selecting keys and deleting by primary key keeps this portable:
        // PostgreSQL does not support DELETE ... LIMIT.
        do {
            $ids = $query()->orderBy('occurred_at')->limit($chunk)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::connection($connection)
                ->table(DatabaseSink::TABLE)
                ->whereIn('id', $ids->all())
                ->delete();
        } while ($ids->count() === $chunk);

        $this->components->info(sprintf('Deleted %d record(s).', $deleted));

        return self::SUCCESS;
    }
}
