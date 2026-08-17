<?php

namespace LiveNetworks\LnStarter\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LiveNetworks\LnStarter\LnStarterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Sanctum\SanctumServiceProvider::class,
            LnStarterServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // SQLite runs against a fresh :memory: database per test, so isolation
        // is free. Server-backed connections persist between test classes, and
        // each class builds only the tables it owns — without this, leftovers
        // leak across classes and results become order-dependent.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::dropAllTables();
        }
    }

    protected function defineEnvironment($app): void
    {
        $connection = getenv('DB_CONNECTION') ?: 'testing';

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
        $app['config']->set('database.default', $connection);

        if ($connection === 'mysql') {
            $app['config']->set('database.connections.mysql', [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'ln_starter',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'unix_socket' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                // Pinned rather than left to the server default: the row-lock
                // race test is meaningless on MyISAM, which silently ignores
                // transactions and lockForUpdate().
                'engine' => 'InnoDB',
            ]);

            return;
        }

        if ($connection === 'pgsql') {
            $app['config']->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '5432',
                'database' => getenv('DB_DATABASE') ?: 'ln_starter',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: 'postgres',
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);

            return;
        }

        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
