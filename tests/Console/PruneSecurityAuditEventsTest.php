<?php

namespace LiveNetworks\LnStarter\Tests\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LiveNetworks\LnStarter\Security\Sinks\DatabaseSink;
use LiveNetworks\LnStarter\Tests\TestCase;

class PruneSecurityAuditEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists(DatabaseSink::TABLE);
        Schema::create(DatabaseSink::TABLE, function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('event_name', 96);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestamp('occurred_at');
            $table->string('severity', 16)->default('info');
            $table->string('outcome', 16)->default('success');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function event(string $occurredAt): string
    {
        $id = (string) Str::ulid();

        DB::table(DatabaseSink::TABLE)->insert([
            'id' => $id,
            'event_name' => 'auth.session.created',
            'schema_version' => 1,
            'occurred_at' => $occurredAt,
            'severity' => 'info',
            'outcome' => 'success',
            'created_at' => now(),
        ]);

        return $id;
    }

    public function test_a_missing_table_is_reported_rather_than_crashing(): void
    {
        Schema::dropIfExists(DatabaseSink::TABLE);

        $this->artisan('ln-starter:security-audit-prune')
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    }

    public function test_dry_run_reports_matches_without_deleting(): void
    {
        $old = $this->event(now()->subDays(120)->toDateTimeString());
        $fresh = $this->event(now()->subDay()->toDateTimeString());

        $this->artisan('ln-starter:security-audit-prune', ['--days' => 90, '--dry-run' => true])
            ->expectsOutputToContain('matched 1 record(s)')
            ->expectsOutputToContain('no records were deleted')
            ->assertSuccessful();

        $this->assertDatabaseHas(DatabaseSink::TABLE, ['id' => $old]);
        $this->assertDatabaseHas(DatabaseSink::TABLE, ['id' => $fresh]);
    }

    public function test_only_records_older_than_the_cutoff_are_deleted(): void
    {
        $old = $this->event(now()->subDays(120)->toDateTimeString());
        $fresh = $this->event(now()->subDays(10)->toDateTimeString());

        $this->artisan('ln-starter:security-audit-prune', ['--days' => 90])
            ->expectsOutputToContain('Deleted 1 record(s)')
            ->assertSuccessful();

        $this->assertDatabaseMissing(DatabaseSink::TABLE, ['id' => $old]);
        $this->assertDatabaseHas(DatabaseSink::TABLE, ['id' => $fresh]);
    }

    /**
     * The cutoff is strict: a record exactly at the boundary is inside the
     * retention window and must survive.
     */
    public function test_the_cutoff_boundary_is_not_deleted(): void
    {
        $this->travelTo(now()->startOfSecond());

        $justInside = $this->event(now()->subDays(90)->addSecond()->toDateTimeString());
        $justOutside = $this->event(now()->subDays(90)->subSecond()->toDateTimeString());

        $this->artisan('ln-starter:security-audit-prune', ['--days' => 90])->assertSuccessful();

        $this->assertDatabaseHas(DatabaseSink::TABLE, ['id' => $justInside]);
        $this->assertDatabaseMissing(DatabaseSink::TABLE, ['id' => $justOutside]);
    }

    public function test_chunking_deletes_every_matching_record(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->event(now()->subDays(100 + $i)->toDateTimeString());
        }
        $fresh = $this->event(now()->toDateTimeString());

        $this->artisan('ln-starter:security-audit-prune', ['--days' => 90, '--chunk' => 2])
            ->expectsOutputToContain('Deleted 7 record(s)')
            ->assertSuccessful();

        $this->assertSame(1, DB::table(DatabaseSink::TABLE)->count());
        $this->assertDatabaseHas(DatabaseSink::TABLE, ['id' => $fresh]);
    }

    public function test_production_requires_force(): void
    {
        $this->app['env'] = 'production';
        $old = $this->event(now()->subDays(120)->toDateTimeString());

        $this->artisan('ln-starter:security-audit-prune', ['--days' => 90])
            ->expectsOutputToContain('without --force')
            ->assertFailed();

        $this->assertDatabaseHas(DatabaseSink::TABLE, ['id' => $old]);

        $this->artisan('ln-starter:security-audit-prune', ['--days' => 90, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing(DatabaseSink::TABLE, ['id' => $old]);
    }

    public function test_a_nonsensical_retention_window_is_rejected(): void
    {
        $this->artisan('ln-starter:security-audit-prune', ['--days' => 0])
            ->expectsOutputToContain('at least 1 day')
            ->assertFailed();
    }

    public function test_rerunning_is_idempotent(): void
    {
        $this->event(now()->subDays(120)->toDateTimeString());

        $this->artisan('ln-starter:security-audit-prune', ['--days' => 90])->assertSuccessful();
        $this->artisan('ln-starter:security-audit-prune', ['--days' => 90])
            ->expectsOutputToContain('Nothing to delete')
            ->assertSuccessful();
    }
}
