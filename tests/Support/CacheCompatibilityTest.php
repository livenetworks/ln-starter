<?php

namespace LiveNetworks\LnStarter\Tests\Support;

use Illuminate\Support\Facades\Route;
use LiveNetworks\LnStarter\Security\RequestContext;
use LiveNetworks\LnStarter\Security\SecurityEventDispatcher;
use LiveNetworks\LnStarter\Support\SecurityEventLogger;
use LiveNetworks\LnStarter\Tests\TestCase;

/**
 * `config:cache` and `route:cache` serialize what the package contributes.
 * A closure in config or a closure-action route makes the whole application
 * un-cacheable — a failure that only shows up in production deployment, never
 * in a test run against an uncached container.
 */
class CacheCompatibilityTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ln-starter.auth.enabled', true);
        $app['config']->set('ln-starter.auth.peppers.current', 'v1');
        $app['config']->set('ln-starter.auth.peppers.keys', ['v1' => str_repeat('a', 32)]);
    }

    public function test_the_package_config_is_serializable(): void
    {
        $config = config('ln-starter');

        $this->assertIsArray($config);

        // var_export is what config:cache writes; a closure or resource makes
        // it throw or emit something PHP cannot re-read.
        $exported = var_export($config, true);

        $this->assertIsString($exported);
        $this->assertStringNotContainsString('Closure', $exported);

        $roundTripped = eval('return ' . $exported . ';');
        $this->assertSame($config, $roundTripped);
    }

    public function test_no_package_config_value_is_a_closure_or_object(): void
    {
        $walk = function (array $values, string $path) use (&$walk): void {
            foreach ($values as $key => $value) {
                $here = $path === '' ? (string) $key : "{$path}.{$key}";

                if (is_array($value)) {
                    $walk($value, $here);
                    continue;
                }

                $this->assertFalse(
                    $value instanceof \Closure,
                    "ln-starter.{$here} is a closure and would break config:cache"
                );
                $this->assertFalse(
                    is_resource($value),
                    "ln-starter.{$here} is a resource and would break config:cache"
                );
            }
        };

        $walk(config('ln-starter'), '');
    }

    public function test_every_package_route_has_a_serializable_action(): void
    {
        $packageRoutes = array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_contains((string) ($route->getAction('controller') ?? ''), 'LnStarter')
                || str_starts_with((string) $route->getName(), 'auth.')
                || in_array($route->getName(), ['login', 'logout', 'magic.wait', 'magic.status'], true)
        );

        $this->assertNotEmpty($packageRoutes, 'No package routes were registered.');

        foreach ($packageRoutes as $route) {
            $action = $route->getAction();

            $this->assertFalse(
                ($action['uses'] ?? null) instanceof \Closure,
                'Route ' . ($route->getName() ?? $route->uri()) . ' uses a closure and cannot be route:cached'
            );

            $this->assertIsString(
                $action['controller'] ?? null,
                'Route ' . ($route->getName() ?? $route->uri()) . ' has no serializable controller reference'
            );
        }
    }

    public function test_route_definitions_survive_serialization(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (!str_contains((string) ($route->getAction('controller') ?? ''), 'LnStarter')) {
                continue;
            }

            // Laravel serializes the compiled route collection for route:cache.
            $serialized = serialize($route->getAction());
            $this->assertIsString($serialized);
            $this->assertIsArray(unserialize($serialized));
        }
    }

    /**
     * Simulates what Octane does between requests: the scoped bindings are
     * flushed while singletons survive.
     */
    public function test_a_simulated_octane_scope_reset_leaves_no_stale_correlation_state(): void
    {
        $logger = $this->app->make(SecurityEventLogger::class);
        $dispatcher = $this->app->make(SecurityEventDispatcher::class);

        $this->app->make(RequestContext::class)->startRequest(null);
        $firstId = $logger->requestId();

        $this->app->forgetScopedInstances();

        $this->app->make(RequestContext::class)->startRequest(null);
        $secondId = $logger->requestId();

        $this->assertNotSame($firstId, $secondId);

        // The singletons themselves must survive the reset — the sink registry
        // lives on the dispatcher and re-registering per request would drop
        // any sink an application added at boot.
        $this->assertSame($dispatcher, $this->app->make(SecurityEventDispatcher::class));
        $this->assertSame($logger, $this->app->make(SecurityEventLogger::class));
    }

    public function test_booting_the_package_issues_no_database_query(): void
    {
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        // Cheap readiness runs on every boot; it must never touch the database.
        $this->app->make(\LiveNetworks\LnStarter\Support\SecurityObservabilityConfiguration::class)->validate();
        $this->app->make(\LiveNetworks\LnStarter\Support\AuthV2Configuration::class)->validate();

        $this->assertSame([], $queries, 'Package boot ran a query: ' . implode('; ', $queries));
    }
}
