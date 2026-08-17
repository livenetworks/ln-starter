<?php

namespace LiveNetworks\LnStarter;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;

class LnStarterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $configPath = __DIR__ . '/../config/ln-starter.php';

        // Auth v2 adds nested settings to configs published by auth v1. A
        // shallow package merge would let the old `auth` array hide peppers,
        // eligibility and rate-limit defaults, making upgrade boot impossible.
        if (method_exists($this, 'replaceConfigRecursivelyFrom')) {
            $this->replaceConfigRecursivelyFrom($configPath, 'ln-starter');
        } else {
            $this->mergeConfigFrom($configPath, 'ln-starter');
            $this->app->make('config')->set('ln-starter', array_replace_recursive(
                require $configPath,
                $this->app->make('config')->get('ln-starter', [])
            ));
        }

        $this->app->singleton(\LiveNetworks\LnStarter\Support\LocaleManager::class);
        $this->app->singleton(\LiveNetworks\LnStarter\Support\MagicLoginProofs::class);
        $this->app->singleton(\LiveNetworks\LnStarter\Support\SecurityEventLogger::class);
        $this->app->singleton(\LiveNetworks\LnStarter\Support\AuthV2UpgradeAudit::class);
        $this->app->bind(
            \LiveNetworks\LnStarter\Contracts\AuthEligibility::class,
            fn ($app) => $app->make(config(
                'ln-starter.auth.eligibility',
                \LiveNetworks\LnStarter\Support\DefaultAuthEligibility::class
            ))
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
        $this->validateAuthV2Configuration();
        $this->publishSkillOnInstall();
    }

    protected function registerExceptionHandling(): void
    {
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        \LiveNetworks\LnStarter\Exceptions\AuthExceptionHandler::register($handler);
    }

    protected function registerMiddlewareAliases(): void
    {
        $router = $this->app->make(Router::class);

        // Core aliases — always registered, regardless of published config
        $core = [
            'sanctum.token'      => \LiveNetworks\LnStarter\Http\Middleware\AuthenticateWithSanctum::class,
            'cookie.auth'        => \LiveNetworks\LnStarter\Http\Middleware\AuthorizationFromCookie::class,
            'disable-csrf'       => \LiveNetworks\LnStarter\Http\Middleware\DisableCsrf::class,
            'ln.auth'            => \LiveNetworks\LnStarter\Http\Middleware\RequireAuthentication::class,
            'ln.locale'          => \LiveNetworks\LnStarter\Http\Middleware\SetLocale::class,
            'ln.locale.prepare'  => \LiveNetworks\LnStarter\Http\Middleware\PrepareLocale::class,
            'ln.locale.redirect' => \LiveNetworks\LnStarter\Http\Middleware\RedirectToLocale::class,
        ];

        // Project config can add extra aliases or override core ones
        $custom = config('ln-starter.middleware_aliases', []);

        foreach (array_merge($core, $custom) as $alias => $class) {
            $router->aliasMiddleware($alias, $class);
        }
    }

    protected function registerViews(): void
    {
        $this->loadViewsFrom(
            __DIR__ . '/../resources/views', 'ln-starter'
        );

        Blade::component('ln.toast', \LiveNetworks\LnStarter\View\Components\Toast::class);
        Blade::component('ln.modal', \LiveNetworks\LnStarter\View\Components\Modal::class);
        Blade::component('ln.logout-form', \LiveNetworks\LnStarter\View\Components\LogoutForm::class);
        Blade::component('ln.lang-switcher', \LiveNetworks\LnStarter\View\Components\LangSwitcher::class);
    }

    protected function registerAuthRoutes(): void
    {
        if (!config('ln-starter.auth.enabled', false)) {
            return;
        }

        $locale = $this->app->make(\LiveNetworks\LnStarter\Support\LocaleManager::class);

        if ($locale->multilingual()) {
            // Localized auth routes (names preserved by routes/auth.php).
            Route::prefix('{locale}')
                ->middleware(['web', 'ln.locale'])
                ->group(__DIR__ . '/../routes/auth.php');

            // Bare /login → negotiate + redirect to /{locale}/login.
            // UNNAMED so it does not clobber the 'login' name on the localized route.
            Route::middleware(['web', 'ln.locale.redirect'])
                ->get('/login', fn () => abort(404));
        } else {
            // Single-language: exactly as before — zero behavior change.
            Route::middleware('web')
                ->group(__DIR__ . '/../routes/auth.php');
        }
    }

    protected function registerMigrations(): void
    {
        // Auth v2 uses framework sessions and only needs its own attempt table.
        if (config('ln-starter.auth.enabled', false)) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/auth-v2');
        }
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
            __DIR__ . '/../database/migrations/auth-v2/create_magic_login_attempts_table.php' => database_path('migrations/create_magic_login_attempts_table.php'),
        ], 'ln-starter-migrations');

        // Optional generic Sanctum API support; not required by built-in auth v2.
        $this->publishes([
            __DIR__ . '/../database/migrations/create_personal_access_tokens_table.php' => database_path('migrations/create_personal_access_tokens_table.php'),
        ], 'ln-starter-sanctum-migrations');

        // Stubs
        $this->publishes([
            __DIR__ . '/../stubs' => base_path('stubs/ln-starter'),
        ], 'ln-starter-stubs');

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
                \LiveNetworks\LnStarter\Console\CleanupMagicLinkTokensCommand::class,
                \LiveNetworks\LnStarter\Console\AuditAuthV2Command::class,
                \LiveNetworks\LnStarter\Console\CutoverAuthV2Command::class,
                \LiveNetworks\LnStarter\Console\CheckAuthV2ReadinessCommand::class,
            ]);
        }
    }

    protected function validateAuthV2Configuration(): void
    {
        if (!config('ln-starter.auth.enabled', false)) {
            return;
        }

        $this->app->make(\LiveNetworks\LnStarter\Support\AuthV2Configuration::class)->validate();
    }

    protected function publishSkillOnInstall(): void
    {
        $target = base_path('.claude/skills/ln-starter/SKILL.md');
        $source = __DIR__ . '/../skills/ln-starter/SKILL.md';

        if (!file_exists($target) && file_exists($source)) {
            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            copy($source, $target);
        }
    }
}
