<?php

namespace LiveNetworks\LnStarter\Tests\Support;

use LiveNetworks\LnStarter\Support\AuthV2Configuration;
use LiveNetworks\LnStarter\Tests\TestCase;
use RuntimeException;

/**
 * Deployment settings that decide whether auth v2 is actually safe in
 * production. Each test invalidates exactly one setting against an otherwise
 * valid baseline, so a passing test cannot be an accident of ordering.
 */
class ProductionDeploymentReadinessTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ln-starter.auth.enabled', true);
        $app['config']->set('ln-starter.auth.peppers.current', 'v1');
        $app['config']->set('ln-starter.auth.peppers.keys', ['v1' => str_repeat('a', 32)]);
        $app['config']->set('ln-starter.logging.enabled', false);
    }

    private function baseline(): void
    {
        $this->app['env'] = 'production';
        config()->set('app.url', 'https://app.example.test');
        config()->set('session.secure', true);
        config()->set('session.http_only', true);
        config()->set('session.same_site', 'lax');
        config()->set('session.domain', null);
        config()->set('session.driver', 'redis');
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis', ['driver' => 'redis']);
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', ['transport' => 'smtp']);
        config()->set('cache.default', 'database');
    }

    private function assertRefuses(string $fragment): void
    {
        try {
            $this->app->make(AuthV2Configuration::class)->validate();
            $this->fail("Readiness should have refused: {$fragment}");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($fragment, $exception->getMessage());
            $this->assertStringNotContainsString(str_repeat('a', 32), $exception->getMessage());
        }
    }

    public function test_a_valid_production_configuration_passes_every_cheap_check(): void
    {
        $this->baseline();

        // SQLite is still rejected as a production database; that check is
        // exercised separately. Everything up to it must pass.
        $this->assertRefuses('transactional row locking');
    }

    public function test_a_plain_http_app_url_is_refused(): void
    {
        $this->baseline();
        config()->set('app.url', 'http://app.example.test');

        // The magic link inherits APP_URL, so http here means the proof itself
        // travels in the clear.
        $this->assertRefuses('https APP_URL');
    }

    public function test_an_empty_app_url_is_refused(): void
    {
        $this->baseline();
        config()->set('app.url', '');

        $this->assertRefuses('https APP_URL');
    }

    public function test_an_insecure_session_cookie_is_refused(): void
    {
        $this->baseline();
        config()->set('session.secure', false);

        $this->assertRefuses('SESSION_SECURE_COOKIE');
    }

    public function test_a_javascript_readable_session_cookie_is_refused(): void
    {
        $this->baseline();
        config()->set('session.http_only', false);

        $this->assertRefuses('http-only session cookie');
    }

    public function test_same_site_none_is_refused(): void
    {
        $this->baseline();
        config()->set('session.same_site', 'none');

        $this->assertRefuses('same_site');
    }

    public function test_a_top_level_cookie_domain_is_refused(): void
    {
        $this->baseline();
        config()->set('session.domain', '.example');

        $this->assertRefuses('top-level session cookie domain');
    }

    public function test_a_missing_queue_connection_is_refused(): void
    {
        $this->baseline();
        config()->set('queue.connections.redis', null);

        $this->assertRefuses('configured queue connection');
    }

    public function test_a_sync_queue_is_refused(): void
    {
        $this->baseline();
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync', ['driver' => 'sync']);

        $this->assertRefuses('non-sync queue');
    }

    public function test_a_missing_mailer_is_refused(): void
    {
        $this->baseline();
        config()->set('mail.mailers.smtp', null);

        $this->assertRefuses('configured mailer');
    }

    public function test_an_array_session_driver_is_refused(): void
    {
        $this->baseline();
        config()->set('session.driver', 'array');

        $this->assertRefuses('lock-capable session backend');
    }

    /**
     * None of these checks may open a connection or send anything: readiness
     * runs on every boot in production.
     */
    public function test_the_cheap_checks_never_send_mail_or_touch_the_network(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->baseline();

        try {
            $this->app->make(AuthV2Configuration::class)->validate();
        } catch (RuntimeException) {
            // Expected: SQLite is refused. The point is what did NOT happen.
        }

        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_non_production_environments_skip_deployment_checks(): void
    {
        config()->set('app.url', 'http://localhost');
        config()->set('session.secure', false);
        config()->set('queue.default', 'sync');

        $this->app->make(AuthV2Configuration::class)->validate();

        $this->addToAssertionCount(1);
    }
}
