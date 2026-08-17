<?php

namespace LiveNetworks\LnStarter\Tests\Console;

use LiveNetworks\LnStarter\Console\InstallCommand;
use PHPUnit\Framework\TestCase;

class InstallCommandTest extends TestCase
{
    private string $directory;
    private string $createStub;
    private string $additiveStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ln-starter-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);

        $this->createStub = $this->file('create.stub', 'ln create schema');
        $this->additiveStub = $this->file('additive.stub', 'ln additive schema');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && dirname($file) === $this->directory) {
                unlink($file);
            }
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_fresh_laravel_users_migration_is_untouched_and_additive_migration_is_published(): void
    {
        $existing = $this->migration('0001_01_01_000000_create_users_table.php', 'laravel schema');

        $command = new TestableInstallCommand();
        $plan = $command->planFiles($this->directory, $this->createStub, $this->additiveStub, false);
        $status = $command->applyPlan($plan);

        $this->assertSame('published', $status);
        $this->assertSame('additive', $plan['kind']);
        $this->assertSame('laravel schema', file_get_contents($existing));
        $this->assertSame('ln additive schema', file_get_contents($plan['target']));
    }

    public function test_multiple_users_migrations_are_untouched_and_do_not_block_install(): void
    {
        $first = $this->migration('2025_01_01_000000_create_users_table.php', 'first');
        $second = $this->migration('2026_01_01_000000_create_users_table.php', 'second');

        $command = new TestableInstallCommand();
        $plan = $command->planFiles($this->directory, $this->createStub, $this->additiveStub, true);
        $status = $command->applyPlan($plan);

        $this->assertSame('published', $status);
        $this->assertCount(2, $plan['existing']);
        $this->assertSame('first', file_get_contents($first));
        $this->assertSame('second', file_get_contents($second));
        $this->assertSame('ln additive schema', file_get_contents($plan['target']));
    }

    public function test_existing_additive_migration_is_skipped_without_force(): void
    {
        $this->migration('0001_01_01_000000_create_users_table.php', 'laravel schema');
        $additive = $this->migration(
            '0001_01_01_000001_add_ln_starter_names_to_users_table.php',
            'consumer customisation'
        );

        $command = new TestableInstallCommand();
        $plan = $command->planFiles($this->directory, $this->createStub, $this->additiveStub, false);

        $this->assertSame('skipped', $command->applyPlan($plan));
        $this->assertSame('consumer customisation', file_get_contents($additive));
    }

    public function test_force_replaces_only_the_package_additive_migration(): void
    {
        $existing = $this->migration('0001_01_01_000000_create_users_table.php', 'laravel schema');
        $additive = $this->migration(
            '0001_01_01_000001_add_ln_starter_names_to_users_table.php',
            'old additive schema'
        );

        $command = new TestableInstallCommand();
        $plan = $command->planFiles($this->directory, $this->createStub, $this->additiveStub, true);

        $this->assertSame('replaced', $command->applyPlan($plan));
        $this->assertSame('laravel schema', file_get_contents($existing));
        $this->assertSame('ln additive schema', file_get_contents($additive));
    }

    public function test_create_users_migration_is_published_when_none_exists(): void
    {
        $command = new TestableInstallCommand();
        $plan = $command->planFiles($this->directory, $this->createStub, $this->additiveStub, false);

        $this->assertSame('published', $command->applyPlan($plan));
        $this->assertSame('create', $plan['kind']);
        $this->assertSame('ln create schema', file_get_contents($plan['target']));
    }

    private function migration(string $name, string $contents): string
    {
        return $this->file($name, $contents);
    }

    private function file(string $name, string $contents): string
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}

class TestableInstallCommand extends InstallCommand
{
    public function planFiles(
        string $migrationsPath,
        string $createStub,
        string $additiveStub,
        bool $force
    ): array {
        return $this->planUsersMigrationFiles($migrationsPath, $createStub, $additiveStub, $force);
    }

    public function applyPlan(array $plan): string
    {
        return $this->applyUsersMigrationPlan($plan);
    }
}
