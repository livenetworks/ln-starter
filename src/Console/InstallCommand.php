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

        try {
            // Resolve all users-migration decisions before vendor:publish so a
            // known filesystem problem cannot leave a half-published install.
            $usersMigrationPlan = $this->planUsersMigrationFiles(
                database_path('migrations'),
                __DIR__ . '/../../stubs/create_users_table.stub',
                __DIR__ . '/../../stubs/add_ln_starter_names_to_users_table.stub',
                (bool) $this->option('force')
            );
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());
            return self::FAILURE;
        }

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

        $this->publishUsersMigration($usersMigrationPlan);

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

    /**
     * @param array{action: 'skip'|'publish', target: string, stub: string, existing: array<int, string>, kind: 'create'|'additive', replacing: bool} $plan
     */
    protected function publishUsersMigration(array $plan): void
    {
        if (count($plan['existing']) > 1) {
            $this->components->warn('Multiple create-users migrations were found and left unchanged:');
            foreach ($plan['existing'] as $path) {
                $this->line('  - ' . basename($path));
            }
        }

        $status = $this->applyUsersMigrationPlan($plan);

        if ($status === 'skipped') {
            $this->components->warn(
                'LN-Starter users migration already exists and was left unchanged: ' . basename($plan['target'])
            );
            return;
        }

        $label = $plan['kind'] === 'create' ? 'Users migration' : 'Additive users migration';
        $verb = $status === 'replaced' ? 'replaced' : 'published';
        $this->components->info($label . ' ' . $verb . ': ' . basename($plan['target']));
    }

    /**
     * @param array{action: 'skip'|'publish', target: string, stub: string, existing: array<int, string>, kind: 'create'|'additive', replacing: bool} $plan
     * @return 'skipped'|'published'|'replaced'
     */
    protected function applyUsersMigrationPlan(array $plan): string
    {
        if ($plan['action'] === 'skip') {
            return 'skipped';
        }

        if (!copy($plan['stub'], $plan['target'])) {
            throw new \RuntimeException('Failed to publish users migration: ' . $plan['target']);
        }

        return $plan['replacing'] ? 'replaced' : 'published';
    }

    /**
     * Plan a non-destructive users migration publish.
     *
     * Existing create-users migrations always remain consumer-owned. When one
     * or more exist, LN-Starter publishes a separate additive migration.
     *
     * @return array{action: 'skip'|'publish', target: string, stub: string, existing: array<int, string>, kind: 'create'|'additive', replacing: bool}
     */
    protected function planUsersMigrationFiles(
        string $migrationsPath,
        string $createStub,
        string $additiveStub,
        bool $force
    ): array {
        if (!is_dir($migrationsPath) || !is_writable($migrationsPath)) {
            throw new \RuntimeException('Migrations directory is missing or not writable: ' . $migrationsPath);
        }

        foreach ([$createStub, $additiveStub] as $stub) {
            if (!is_file($stub) || !is_readable($stub)) {
                throw new \RuntimeException('Migration stub is missing or not readable: ' . $stub);
            }
        }

        $existing = glob($migrationsPath . '/*_create_users_table.php') ?: [];
        sort($existing);

        if ($existing === []) {
            return [
                'action'   => 'publish',
                'target'   => $migrationsPath . '/0001_01_01_000000_create_users_table.php',
                'stub'     => $createStub,
                'existing' => [],
                'kind'     => 'create',
                'replacing' => false,
            ];
        }

        $publishedAdditive = glob($migrationsPath . '/*_add_ln_starter_names_to_users_table.php') ?: [];
        sort($publishedAdditive);

        if (count($publishedAdditive) > 1) {
            throw new \RuntimeException(
                'Multiple LN-Starter additive users migrations found; resolve them before installing.'
            );
        }

        $target = $publishedAdditive[0]
            ?? $migrationsPath . '/' . $this->nextMigrationTimestamp($migrationsPath)
                . '_add_ln_starter_names_to_users_table.php';

        return [
            'action'   => file_exists($target) && !$force ? 'skip' : 'publish',
            'target'   => $target,
            'stub'     => $additiveStub,
            'existing' => $existing,
            'kind'     => 'additive',
            'replacing' => file_exists($target),
        ];
    }

    /**
     * Return a migration timestamp ordered after every migration currently in
     * the application, including consumer migrations with future timestamps.
     */
    protected function nextMigrationTimestamp(string $migrationsPath): string
    {
        $latest = date('Y_m_d_His');

        foreach (glob($migrationsPath . '/*.php') ?: [] as $migration) {
            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', basename($migration), $matches)) {
                $latest = max($latest, $matches[1]);
            }
        }

        $date = \DateTimeImmutable::createFromFormat('!Y_m_d_His', $latest);
        if (!$date) {
            throw new \RuntimeException('Unable to generate an additive users migration timestamp.');
        }

        return $date->modify('+1 second')->format('Y_m_d_His');
    }
}
