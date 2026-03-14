<?php

namespace LiveNetworks\LnStarter;

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
        $this->registerMiddlewareAliases();
        $this->registerViews();
        $this->registerPublishing();
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

    protected function registerPublishing(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        // Config
        $this->publishes([
            __DIR__ . '/../config/ln-starter.php' => config_path('ln-starter.php'),
        ], 'ln-starter-config');

        // Blade layouts
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/ln-starter'),
        ], 'ln-starter-views');

        // Stubs
        $this->publishes([
            __DIR__ . '/../stubs' => base_path('stubs/ln-starter'),
        ], 'ln-starter-stubs');

        // Claude AI skill
        $this->publishes([
            __DIR__ . '/../skills/ln-starter' => base_path('.claude/skills/ln-starter'),
        ], 'ln-starter-skill');
    }
}
