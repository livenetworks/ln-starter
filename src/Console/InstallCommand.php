<?php

namespace LiveNetworks\LnStarter\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'ln-starter:install
                            {--force : Overwrite existing published files}';

    protected $description = 'Publish all LN-Starter assets (config, layouts, migrations, stubs)';

    public function handle(): int
    {
        $force = $this->option('force') ? ['--force' => true] : [];

        $steps = [
            ['tag' => 'ln-starter-config',     'label' => 'Config'],
            ['tag' => 'ln-starter-layouts',    'label' => 'Layouts'],
            ['tag' => 'ln-starter-views',      'label' => 'Auth views'],
            ['tag' => 'ln-starter-migrations', 'label' => 'Migrations (magic_link_tokens, personal_access_tokens)'],
            ['tag' => 'ln-starter-stubs',      'label' => 'Generator stubs'],
        ];

        foreach ($steps as $step) {
            $this->call('vendor:publish', array_merge([
                '--tag'      => $step['tag'],
                '--provider' => 'LiveNetworks\\LnStarter\\LnStarterServiceProvider',
            ], $force));

            $this->components->info($step['label'] . ' published.');
        }

        $this->publishUsersMigration();

        $this->newLine();
        $this->components->info('LN-Starter installed successfully.');

        return self::SUCCESS;
    }

    protected function publishUsersMigration(): void
    {
        $migrationsPath = database_path('migrations');
        $stub           = __DIR__ . '/../../stubs/create_users_table.stub';

        // Known Laravel default users migration filenames
        $defaults = [
            '0001_01_01_000000_create_users_table.php', // Laravel 11+
            '2014_10_12_000000_create_users_table.php', // Laravel 10
        ];

        $target = null;

        foreach ($defaults as $filename) {
            $path = $migrationsPath . '/' . $filename;
            if (file_exists($path)) {
                $target = $path;
                break;
            }
        }

        // Also remove any previously published ln-starter users migration (different timestamp)
        foreach (glob($migrationsPath . '/*_create_users_table.php') as $existing) {
            if (!in_array(basename($existing), $defaults)) {
                unlink($existing);
            }
        }

        if ($target) {
            // Replace the default migration in-place (keeps original filename/sort order)
            copy($stub, $target);
            $this->components->info('Users migration replaced (Laravel default overwritten).');
        } else {
            // No default found — publish with a fixed well-known name
            $dest = $migrationsPath . '/0001_01_01_000000_create_users_table.php';
            copy($stub, $dest);
            $this->components->info('Users migration published.');
        }
    }
}
