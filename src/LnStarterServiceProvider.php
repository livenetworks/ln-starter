<?php

namespace LiveNetworks\LnStarter;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;

class LnStarterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/ln-starter.php', 'ln-starter'
        );
    }

    public function boot(): void
    {
        $this->registerExceptionHandling();
        $this->registerMiddlewareAliases();
        $this->registerViews();
        $this->registerAuthRoutes();
        $this->registerMigrations();
        $this->registerPublishing();
        $this->registerCommands();
    }

    protected function registerExceptionHandling(): void
    {
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        \LiveNetworks\LnStarter\Exceptions\AuthExceptionHandler::register($handler);
    }

    protected function registerMiddlewareAliases(): void
    {
        $router = $this->app->make(Router::class);

        $aliases = config('ln-starter.middleware_aliases', []);

        foreach ($aliases as $alias => $class) {
            $router->aliasMiddleware($alias, $class);
        }
    }

    protected function registerViews(): void
    {
        $this->loadViewsFrom(
            __DIR__ . '/../resources/views', 'ln-starter'
        );
    }

    protected function registerAuthRoutes(): void
    {
        if (!config('ln-starter.auth.enabled', false)) {
            return;
        }

        Route::middleware('web')
            ->group(__DIR__ . '/../routes/auth.php');
    }

    protected function registerMigrations(): void
    {
        if (!config('ln-starter.auth.enabled', false)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function registerPublishing(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        // Config
        $this->publishes([
            __DIR__ . '/../config/ln-starter.php' => config_path('ln-starter.php'),
        ], 'ln-starter-config');

        // Layouts — publish to project's views/layouts/
        $this->publishes([
            __DIR__ . '/../resources/views/layouts/_app.scaffold.blade.php' => resource_path('views/layouts/_app.blade.php'),
            __DIR__ . '/../resources/views/layouts/_ln.blade.php'          => resource_path('views/layouts/_ln.blade.php'),
            __DIR__ . '/../resources/views/layouts/_ajax.blade.php'        => resource_path('views/layouts/_ajax.blade.php'),
            __DIR__ . '/../resources/views/layouts/_auth.blade.php'        => resource_path('views/layouts/_auth.blade.php'),
        ], 'ln-starter-layouts');

        // Auth views — publish to vendor override path
        $this->publishes([
            __DIR__ . '/../resources/views/auth'   => resource_path('views/vendor/ln-starter/auth'),
            __DIR__ . '/../resources/views/emails' => resource_path('views/vendor/ln-starter/emails'),
        ], 'ln-starter-views');

        // Auth SCSS
        $this->publishes([
            __DIR__ . '/../resources/scss/auth.scss' => resource_path('scss/auth.scss'),
        ], 'ln-starter-auth-css');

        // Migrations
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'ln-starter-migrations');

        // Stubs
        $this->publishes([
            __DIR__ . '/../stubs' => base_path('stubs/ln-starter'),
        ], 'ln-starter-stubs');

        // Users migration stub — publish and rename to a dated migration file
        $this->publishes([
            __DIR__ . '/../stubs/create_users_table.stub' => database_path('migrations/' . date('Y_m_d_His') . '_create_users_table.php'),
        ], 'ln-starter-users-migration');

        // Claude AI skill
        $this->publishes([
            __DIR__ . '/../skills/ln-starter' => base_path('.claude/skills/ln-starter'),
        ], 'ln-starter-skill');
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \LiveNetworks\LnStarter\Console\InstallCommand::class,
            ]);
        }
    }
}
