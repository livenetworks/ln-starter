<?php

namespace LiveNetworks\LnStarter\Tests\Auth;

use LiveNetworks\LnStarter\Tests\TestCase;

/**
 * The shipped auth layout must render without a frontend build.
 *
 * Every other auth test overrides ln-starter.auth.layout with a fixture, so
 * the layout the package actually ships was never rendered by the suite. It
 * called @vite() unconditionally, which throws when the manifest is missing
 * or does not list an entry — so a consumer who had installed the package but
 * not yet run `npm run build` got HTTP 500 on /login, and any deployment that
 * skipped the asset build took authentication down. Caught by the consumer
 * harness, not by this suite; these tests close that gap.
 */
class AuthPageRenderingTest extends TestCase
{
    private string $publicPath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ln-starter.auth.enabled', true);
        $app['config']->set('ln-starter.auth.home_route', 'home');
        // Deliberately NOT a fixture: the point is the layout that ships.
        $app['config']->set('ln-starter.auth.layout', 'ln-starter::layouts._auth');
        $app['config']->set('ln-starter.auth.peppers.current', 'v1');
        $app['config']->set('ln-starter.auth.peppers.keys', ['v1' => str_repeat('a', 32)]);
        $app['config']->set('ln-starter.logging.enabled', false);

        // A public directory this test owns, so a manifest can be added and
        // removed without touching the testbench skeleton.
        $this->publicPath = sys_get_temp_dir() . '/ln-public-' . bin2hex(random_bytes(6));
        mkdir($this->publicPath . '/build', 0700, true);

        $app->usePublicPath($this->publicPath);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/_test/home', static fn () => 'home')->name('home');
    }

    protected function tearDown(): void
    {
        foreach ([
            $this->publicPath . '/build/manifest.json',
            $this->publicPath . '/hot',
        ] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->publicPath . '/build')) {
            rmdir($this->publicPath . '/build');
        }

        if (is_dir($this->publicPath)) {
            rmdir($this->publicPath);
        }

        parent::tearDown();
    }

    public function test_the_login_page_renders_without_a_vite_build(): void
    {
        $this->assertFileDoesNotExist($this->publicPath . '/build/manifest.json');

        $response = $this->get('/login');

        $response->assertOk();
        $this->assertStringNotContainsString('/build/', $response->getContent());
    }

    public function test_the_code_page_renders_without_a_vite_build(): void
    {
        $this->get('/auth/magic/code')->assertOk();
    }

    /**
     * The guard must not silently disable styling for consumers who did build:
     * with a manifest listing both entries, the tags are emitted as before.
     */
    public function test_a_built_manifest_still_emits_the_asset_tags(): void
    {
        file_put_contents($this->publicPath . '/build/manifest.json', json_encode([
            'resources/scss/auth.scss' => [
                'file' => 'assets/auth-test.css',
                'src' => 'resources/scss/auth.scss',
                'isEntry' => true,
            ],
            'resources/js/app.js' => [
                'file' => 'assets/app-test.js',
                'src' => 'resources/js/app.js',
                'isEntry' => true,
            ],
        ]));

        $content = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('assets/auth-test.css', $content);
        $this->assertStringContainsString('assets/app-test.js', $content);
    }

    /**
     * A manifest that exists but does not list the package's entries is the
     * common case straight after `npm run build` on an application that has
     * not added them to vite.config.js. @vite() throws there too.
     */
    public function test_a_manifest_without_the_entries_does_not_break_the_page(): void
    {
        file_put_contents($this->publicPath . '/build/manifest.json', json_encode([
            'resources/css/app.css' => [
                'file' => 'assets/app-test.css',
                'src' => 'resources/css/app.css',
                'isEntry' => true,
            ],
        ]));

        $this->get('/login')->assertOk();
    }
}
