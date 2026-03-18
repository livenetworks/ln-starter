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
            ['tag' => 'ln-starter-auth-css',   'label' => 'Auth SCSS'],
        ];

        foreach ($steps as $step) {
            $this->call('vendor:publish', array_merge([
                '--tag'      => $step['tag'],
                '--provider' => 'LiveNetworks\\LnStarter\\LnStarterServiceProvider',
            ], $force));

            $this->components->info($step['label'] . ' published.');
        }

        $this->publishUsersMigration();
        $this->publishUserModel();
        $this->injectViteEntry('resources/scss/auth.scss');
        $this->checkNpmDependencies(['sass', 'ln-acme']);

        $this->newLine();
        $this->components->info('LN-Starter installed successfully.');
        $this->components->warn('Run `npm run build` or `npm run dev` to compile the new auth styles.');

        return self::SUCCESS;
    }

    protected function injectViteEntry(string $entry): void
    {
        $viteConfig = base_path('vite.config.js');

        if (!file_exists($viteConfig)) {
            $this->components->warn('vite.config.js not found — add ' . $entry . ' to Vite input manually.');
            return;
        }

        $contents = file_get_contents($viteConfig);

        if (str_contains($contents, $entry)) {
            $this->components->info('Vite entry already present: ' . $entry);
            return;
        }

        // Insert before the first existing entry in the input array
        $contents = preg_replace(
            "/input:\s*\[/",
            "input: [\n            '" . $entry . "',",
            $contents,
            limit: 1
        );

        file_put_contents($viteConfig, $contents);
        $this->components->info('Vite entry added: ' . $entry);
    }

    protected function checkNpmDependencies(array $packages): void
    {
        $missing = [];

        foreach ($packages as $package) {
            if (!is_dir(base_path('node_modules/' . $package))) {
                $missing[] = $package;
            }
        }

        if (empty($missing)) {
            return;
        }

        $this->components->warn(
            'Missing npm dependencies: ' . implode(', ', $missing)
        );
        $this->components->warn(
            'Run: npm install ' . implode(' ', $missing) . ' --save-dev'
        );
    }

    protected function publishUserModel(): void
    {
        $stub   = __DIR__ . '/../../stubs/User.stub';
        $target = app_path('Models/User.php');

        if (file_exists($target) && !$this->option('force')) {
            $this->components->warn('User model already exists — use --force to overwrite.');
            return;
        }

        copy($stub, $target);
        $this->components->info('User model published (HasApiTokens + first_name/last_name).');
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

        // Remove any previously published ln-starter users migration (different timestamp)
        foreach (glob($migrationsPath . '/*_create_users_table.php') as $existing) {
            if (!in_array(basename($existing), $defaults)) {
                $this->components->warn('Removing old migration: ' . basename($existing));
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
