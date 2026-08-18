<?php

namespace LiveNetworks\LnStarter\Tests\Support;

use LiveNetworks\LnStarter\Tests\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The guard protects an unrecoverable operation, so its refusal paths matter
 * more than its happy path. Pure unit test: it must not depend on the very
 * database machinery it is guarding.
 */
class TestDatabaseGuardTest extends TestCase
{
    private string|false $originalOptIn = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Restored rather than cleared: on a server-backed lane the suite's own
        // reset guard reads this variable, so leaking a cleared value would
        // make every later test class refuse to run.
        $this->originalOptIn = getenv(TestDatabaseGuard::OPT_IN);
        putenv(TestDatabaseGuard::OPT_IN . '=1');
    }

    protected function tearDown(): void
    {
        if ($this->originalOptIn === false) {
            putenv(TestDatabaseGuard::OPT_IN);
        } else {
            putenv(TestDatabaseGuard::OPT_IN . '=' . $this->originalOptIn);
        }

        parent::tearDown();
    }

    public function test_a_scratch_database_in_testing_with_opt_in_is_allowed(): void
    {
        $this->assertNull(
            TestDatabaseGuard::refusalReason('testing', 'mysql', 'ln_starter_scratch')
        );
    }

    public function test_a_non_testing_environment_is_refused(): void
    {
        foreach (['production', 'local', 'staging', ''] as $env) {
            $this->assertStringContainsString(
                'APP_ENV',
                (string) TestDatabaseGuard::refusalReason($env, 'mysql', 'ln_starter')
            );
        }
    }

    public function test_a_missing_opt_in_is_refused(): void
    {
        putenv(TestDatabaseGuard::OPT_IN);

        $this->assertStringContainsString(
            TestDatabaseGuard::OPT_IN,
            (string) TestDatabaseGuard::refusalReason('testing', 'mysql', 'ln_starter_ci')
        );
    }

    /**
     * Only the exact string "1" authorises the reset. Anything else — a typo,
     * a leftover "false", a truthy-looking word — must refuse, because the
     * operation it guards cannot be undone.
     */
    public function test_only_an_exact_one_authorises_the_reset(): void
    {
        foreach (['0', 'false', 'no', 'yes', 'true', 'on', '1 ', ' 1', '01', 'TRUE', ''] as $value) {
            putenv(TestDatabaseGuard::OPT_IN . '=' . $value);

            $this->assertNotNull(
                TestDatabaseGuard::refusalReason('testing', 'mysql', 'ln_starter_ci'),
                "opt-in value " . var_export($value, true) . " must not authorise a reset"
            );
        }

        putenv(TestDatabaseGuard::OPT_IN . '=1');
        $this->assertNull(TestDatabaseGuard::refusalReason('testing', 'mysql', 'ln_starter_ci'));
    }

    public function test_an_unlisted_connection_is_refused(): void
    {
        $this->assertStringContainsString(
            'not in the allow-list',
            (string) TestDatabaseGuard::refusalReason('testing', 'reporting_replica', 'ln_starter_ci')
        );
    }

    /**
     * `ln_starter` is a plausible name for a developer's real local database,
     * so it is no longer accepted.
     */
    public function test_the_generic_package_name_is_no_longer_allowed(): void
    {
        $this->assertNotNull(TestDatabaseGuard::refusalReason('testing', 'mysql', 'ln_starter'));
        $this->assertNotNull(TestDatabaseGuard::refusalReason('testing', 'mysql', 'testing'));
    }

    public function test_an_unknown_connection_or_database_is_refused(): void
    {
        $this->assertNotNull(TestDatabaseGuard::refusalReason('testing', null, 'ln_starter_ci'));
        $this->assertNotNull(TestDatabaseGuard::refusalReason('testing', '  ', 'ln_starter_ci'));
        $this->assertNotNull(TestDatabaseGuard::refusalReason('testing', 'mysql', null));
        $this->assertNotNull(TestDatabaseGuard::refusalReason('testing', 'mysql', ''));
    }

    /**
     * The allow-list already excludes these, but they are checked explicitly so
     * that widening the allow-list later cannot accidentally admit one.
     */
    public function test_production_like_names_are_refused_even_with_opt_in(): void
    {
        foreach ([
            'production',
            'app_production',
            'live_db',
            'my_app',
            'customer_data',
            'tenant_7',
            'staging_db',
            'backup_2026',
        ] as $name) {
            $reason = TestDatabaseGuard::refusalReason('testing', 'mysql', $name);

            $this->assertNotNull($reason, "expected refusal for {$name}");
        }
    }

    public function test_an_unlisted_but_innocuous_name_is_still_refused(): void
    {
        // Fail closed: unknown is not the same as safe.
        $this->assertStringContainsString(
            'allow-list',
            (string) TestDatabaseGuard::refusalReason('testing', 'pgsql', 'scratch_db_17')
        );
    }

    public function test_assert_throws_with_actionable_guidance(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to drop all tables');

        TestDatabaseGuard::assertResettable('production', 'mysql', 'ln_starter_ci');
    }

    /**
     * A correctly named database on a remote server is still someone's real
     * data, so loopback is required unless a second opt-in says otherwise.
     */
    public function test_a_remote_host_is_refused_without_its_own_opt_in(): void
    {
        $this->assertStringContainsString(
            'is not loopback',
            (string) TestDatabaseGuard::refusalReason('testing', 'mysql', 'ln_starter_ci', 'db.internal.example')
        );

        putenv(TestDatabaseGuard::ALLOW_REMOTE . '=1');
        $this->assertNull(
            TestDatabaseGuard::refusalReason('testing', 'mysql', 'ln_starter_ci', 'db.internal.example')
        );
        putenv(TestDatabaseGuard::ALLOW_REMOTE);
    }

    public function test_loopback_hosts_are_permitted_by_default(): void
    {
        foreach (['127.0.0.1', 'localhost', '::1', ''] as $host) {
            $this->assertNull(
                TestDatabaseGuard::refusalReason('testing', 'mysql', 'ln_starter_ci', $host),
                "loopback host {$host} should be permitted"
            );
        }
    }

    public function test_the_ci_database_name_is_permitted(): void
    {
        // Matches DB_DATABASE in .github/workflows/tests.yml.
        $this->assertNull(TestDatabaseGuard::refusalReason('testing', 'mysql', 'ln_starter_ci', '127.0.0.1'));
        $this->assertNull(TestDatabaseGuard::refusalReason('testing', 'pgsql', 'ln_starter_ci', '127.0.0.1'));
    }
}
