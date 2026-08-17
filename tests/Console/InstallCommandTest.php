<?php

namespace LiveNetworks\LnStarter\Tests\Console;

use LiveNetworks\LnStarter\Console\InstallCommand;
use PHPUnit\Framework\TestCase;

class InstallCommandTest extends TestCase
{
    private string $directory;
    private string $stub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ln-starter-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);

        $this->stub = $this->directory . DIRECTORY_SEPARATOR . 'users.stub';
        file_put_contents($this->stub, 'replacement');
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

    public function test_existing_users_migration_is_unchanged_without_force(): void
    {
        $existing = $this->migration('2026_01_01_000000_create_users_table.php', 'custom schema');

        $result = (new TestableInstallCommand())->publishFiles($this->directory, $this->stub, false);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('custom schema', file_get_contents($existing));
    }

    public function test_multiple_users_migrations_are_never_changed_even_with_force(): void
    {
        $first = $this->migration('2026_01_01_000000_create_users_table.php', 'first');
        $second = $this->migration('2026_01_02_000000_create_users_table.php', 'second');

        $result = (new TestableInstallCommand())->publishFiles($this->directory, $this->stub, true);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('first', file_get_contents($first));
        $this->assertSame('second', file_get_contents($second));
    }

    public function test_force_replaces_exactly_one_existing_users_migration(): void
    {
        $existing = $this->migration('2026_01_01_000000_create_users_table.php', 'custom schema');

        $result = (new TestableInstallCommand())->publishFiles($this->directory, $this->stub, true);

        $this->assertSame('replaced', $result['status']);
        $this->assertSame('replacement', file_get_contents($existing));
    }

    public function test_new_users_migration_is_published_when_none_exists(): void
    {
        $result = (new TestableInstallCommand())->publishFiles($this->directory, $this->stub, false);

        $this->assertSame('published', $result['status']);
        $this->assertSame('replacement', file_get_contents($result['target']));
    }

    private function migration(string $name, string $contents): string
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}

class TestableInstallCommand extends InstallCommand
{
    public function publishFiles(string $migrationsPath, string $stub, bool $force): array
    {
        return $this->publishUsersMigrationFiles($migrationsPath, $stub, $force);
    }
}
