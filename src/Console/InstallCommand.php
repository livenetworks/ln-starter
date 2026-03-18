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
            ['tag' => 'ln-starter-config',           'label' => 'Config'],
            ['tag' => 'ln-starter-layouts',          'label' => 'Layouts'],
            ['tag' => 'ln-starter-views',            'label' => 'Auth views'],
            ['tag' => 'ln-starter-migrations',       'label' => 'Migrations (magic_link_tokens, personal_access_tokens)'],
            ['tag' => 'ln-starter-users-migration',  'label' => 'Users migration stub'],
            ['tag' => 'ln-starter-stubs',            'label' => 'Generator stubs'],
        ];

        foreach ($steps as $step) {
            $this->call('vendor:publish', array_merge([
                '--tag'      => $step['tag'],
                '--provider' => 'LiveNetworks\\LnStarter\\LnStarterServiceProvider',
            ], $force));

            $this->components->info($step['label'] . ' published.');
        }

        $this->newLine();
        $this->components->warn('Remember to delete Laravel\'s default users migration before running migrations:');
        $this->line('  <fg=gray>database/migrations/0001_01_01_000000_create_users_table.php</>');

        $this->newLine();
        $this->components->info('LN-Starter installed successfully.');

        return self::SUCCESS;
    }
}
