<?php

namespace LiveNetworks\LnStarter\Tests\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LiveNetworks\LnStarter\Support\AuthV2Configuration;
use LiveNetworks\LnStarter\Tests\TestCase;
use PDOException;
use RuntimeException;

/**
 * The storage-engine probe must fail closed: anything other than a positive
 * "InnoDB" answer means we cannot prove row locking works, and readiness
 * must not pass. information_schema cannot be simulated on SQLite, so the
 * failure paths are driven through the query layer directly.
 */
class StorageEngineReadinessTest extends TestCase
{
    public function test_innodb_is_accepted(): void
    {
        DB::shouldReceive('selectOne')->once()->andReturn((object) ['ln_engine' => 'InnoDB']);

        AuthV2Configuration::assertInnoDbTable('magic_login_attempts');

        $this->addToAssertionCount(1);
    }

    public function test_innodb_is_accepted_case_insensitively_and_trimmed(): void
    {
        DB::shouldReceive('selectOne')->once()->andReturn((object) ['ln_engine' => ' innodb ']);

        AuthV2Configuration::assertInnoDbTable('magic_login_attempts');

        $this->addToAssertionCount(1);
    }

    public function test_a_non_innodb_engine_fails(): void
    {
        DB::shouldReceive('selectOne')->once()->andReturn((object) ['ln_engine' => 'MyISAM']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('found MyISAM');

        AuthV2Configuration::assertInnoDbTable('magic_login_attempts');
    }

    public function test_a_missing_information_schema_row_fails_closed(): void
    {
        DB::shouldReceive('selectOne')->once()->andReturnNull();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not find magic_login_attempts in information_schema');

        AuthV2Configuration::assertInnoDbTable('magic_login_attempts');
    }

    public function test_a_null_engine_value_fails_closed(): void
    {
        DB::shouldReceive('selectOne')->once()->andReturn((object) ['ln_engine' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('undefined storage engine');

        AuthV2Configuration::assertInnoDbTable('magic_login_attempts');
    }

    public function test_an_empty_engine_value_fails_closed(): void
    {
        DB::shouldReceive('selectOne')->once()->andReturn((object) ['ln_engine' => '   ']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('undefined storage engine');

        AuthV2Configuration::assertInnoDbTable('magic_login_attempts');
    }

    public function test_a_query_failure_fails_closed_without_leaking_credentials(): void
    {
        DB::shouldReceive('selectOne')->once()->andThrow(new QueryException(
            'mysql',
            'select engine from information_schema.tables',
            [],
            new PDOException("Access denied for user 'root'@'localhost' (using password: YES)")
        ));

        try {
            AuthV2Configuration::assertInnoDbTable('magic_login_attempts');
            $this->fail('Expected readiness to fail closed on a query error.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('fails closed', $exception->getMessage());
            $this->assertStringNotContainsString('root', $exception->getMessage());
            $this->assertStringNotContainsString('password', $exception->getMessage());
            $this->assertStringNotContainsString('Access denied', $exception->getMessage());
        }
    }
}
