<?php

namespace LiveNetworks\LnStarter\Tests\Console;

use Illuminate\Support\Facades\Schema;
use LiveNetworks\LnStarter\Security\Sinks\DatabaseSink;
use LiveNetworks\LnStarter\Tests\TestCase;

class SecurityReadinessCommandTest extends TestCase
{
    public function test_readiness_passes_and_prints_a_secret_free_summary(): void
    {
        $secret = 'base64:' . base64_encode(str_repeat('S', 32));
        config()->set('ln-starter.logging.pseudonym.keys.v1', $secret);

        $this->artisan('ln-starter:auth-v2-readiness')
            ->expectsOutputToContain('pseudonym version')
            ->expectsOutputToContain('Readiness checks passed.')
            ->doesntExpectOutputToContain($secret)
            ->doesntExpectOutputToContain('SSSS')
            ->assertSuccessful();
    }

    public function test_readiness_fails_when_the_audit_sink_has_no_table(): void
    {
        Schema::dropIfExists(DatabaseSink::TABLE);
        config()->set('ln-starter.logging.database.enabled', true);

        $this->artisan('ln-starter:auth-v2-readiness')
            ->expectsOutputToContain('Security logging readiness failed')
            ->assertFailed();
    }

    /**
     * The audit migration must install and roll back cleanly, since it is
     * published into consumer applications.
     */
    public function test_the_audit_migration_installs_and_rolls_back(): void
    {
        Schema::dropIfExists(DatabaseSink::TABLE);

        $migration = require __DIR__ . '/../../database/migrations/security/create_ln_security_audit_events_table.php';

        $migration->up();
        $this->assertTrue(Schema::hasTable(DatabaseSink::TABLE));

        foreach ([
            'id', 'event_name', 'schema_version', 'occurred_at', 'severity',
            'outcome', 'reason_code', 'request_id', 'correlation_id',
            'principal_key', 'attempt_id', 'route', 'http_method',
            'status_code', 'auth_method', 'duration_ms', 'context', 'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn(DatabaseSink::TABLE, $column),
                "missing column {$column}"
            );
        }

        // Re-running must be a no-op rather than an error.
        $migration->up();
        $this->assertTrue(Schema::hasTable(DatabaseSink::TABLE));

        $migration->down();
        $this->assertFalse(Schema::hasTable(DatabaseSink::TABLE));
    }
}
