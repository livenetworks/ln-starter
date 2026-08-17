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

        if (!$this->publishUsersMigration()) {
            $this->components->error('LN-Starter installation stopped because the users migration is ambiguous.');
            return self::FAILURE;
        }

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

    protected function publishUsersMigration(): bool
    {
        $migrationsPath = database_path('migrations');
        $stub           = __DIR__ . '/../../stubs/create_users_table.stub';

        $result = $this->publishUsersMigrationFiles(
            $migrationsPath,
            $stub,
            (bool) $this->option('force')
        );

        if ($result['status'] === 'conflict') {
            $this->components->error('Multiple users migrations found; none were changed:');
            foreach ($result['files'] as $path) {
                $this->line('  - ' . basename($path));
            }
            $this->components->warn('Resolve the migration conflict manually and run the command again.');
            return false;
        }

        if ($result['status'] === 'skipped') {
            $this->components->warn(
                'Users migration already exists and was left unchanged: ' . basename($result['target'])
            );
            $this->components->warn('Use --force only if you intentionally want to replace this file.');
            return true;
        }

        $verb = $result['status'] === 'replaced' ? 'replaced' : 'published';
        $this->components->info('Users migration ' . $verb . ': ' . basename($result['target']));

        return true;
    }

    /**
     * Publish the users migration without deleting or ambiguously replacing files.
     *
     * @return array{status: 'conflict'|'skipped'|'replaced'|'published', target?: string, files?: array<int, string>}
     */
    protected function publishUsersMigrationFiles(string $migrationsPath, string $stub, bool $force): array
    {
        $existing = glob($migrationsPath . '/*_create_users_table.php') ?: [];
        sort($existing);

        if (count($existing) > 1) {
            return ['status' => 'conflict', 'files' => $existing];
        }

        if (count($existing) === 1) {
            $target = $existing[0];

            if (!$force) {
                return ['status' => 'skipped', 'target' => $target];
            }

            if (!copy($stub, $target)) {
                throw new \RuntimeException('Failed to replace users migration: ' . $target);
            }

            return ['status' => 'replaced', 'target' => $target];
        }

        $target = $migrationsPath . '/0001_01_01_000000_create_users_table.php';
        if (!copy($stub, $target)) {
            throw new \RuntimeException('Failed to publish users migration: ' . $target);
        }

        return ['status' => 'published', 'target' => $target];
    }
}
