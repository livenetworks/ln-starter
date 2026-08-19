<?php

namespace LiveNetworks\LnStarter\Tests\Auth;

use Illuminate\Foundation\Vite;
use LiveNetworks\LnStarter\Tests\TestCase;

/**
 * The shipped auth layout must render whatever the application's Vite
 * configuration happens to be — including not having built anything yet.
 *
 * Every other auth test overrides ln-starter.auth.layout with a fixture, so
 * the layout the package actually ships was never rendered by the suite. It
 * called the Vite helper unconditionally, which throws when the manifest is
 * missing or does not list an entry: a consumer who had not run `npm run
 * build` got HTTP 500 on /login, and any deploy that skipped the asset build
 * took authentication down. Caught by the consumer harness, not by this suite.
 *
 * The first repair inspected public/hot and public/build/manifest.json by
 * hand, which broke the opposite case: an application using useHotFile(),
 * useBuildDirectory() or useManifestFilename() had correct, built assets and
 * got none of them. Both directions are covered here.
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

        // A public directory this test owns, so manifests can be added and
        // removed without touching the testbench skeleton.
        $this->publicPath = sys_get_temp_dir() . '/ln-public-' . bin2hex(random_bytes(6));
        mkdir($this->publicPath, 0700, true);

        $app->usePublicPath($this->publicPath);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/_test/home', static fn () => 'home')->name('home');
    }

    protected function tearDown(): void
    {
        $this->removePublicPath();

        parent::tearDown();
    }

    /** Scoped cleanup: only the directory this test created. */
    private function removePublicPath(): void
    {
        if (!is_dir($this->publicPath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->publicPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->publicPath);
    }

    /**
     * @param  array<string, array<string, mixed>>  $entries
     */
    private function writeManifest(string $directory, array $entries): void
    {
        $path = $this->publicPath . '/' . $directory;

        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }

        file_put_contents($path . '/manifest.json', json_encode($entries));
    }

    /** @return array<string, array<string, mixed>> */
    private function packageEntries(): array
    {
        return [
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
        ];
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
     * The guard must not silently disable styling for consumers who did build.
     */
    public function test_a_built_manifest_still_emits_the_asset_tags(): void
    {
        $this->writeManifest('build', $this->packageEntries());

        $content = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('assets/auth-test.css', $content);
        $this->assertStringContainsString('assets/app-test.js', $content);
    }

    /**
     * Vite::useBuildDirectory() is a supported, documented configuration. The
     * assets are built and correct; nothing may be dropped because the package
     * looked in the default location instead of the configured one.
     */
    public function test_a_custom_build_directory_still_emits_the_asset_tags(): void
    {
        $this->writeManifest('vendor-assets', $this->packageEntries());
        $this->app->make(Vite::class)->useBuildDirectory('vendor-assets');

        $content = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('vendor-assets/assets/auth-test.css', $content);
        $this->assertStringContainsString('vendor-assets/assets/app-test.js', $content);
        $this->assertFileDoesNotExist($this->publicPath . '/build/manifest.json');
    }

    /**
     * With the dev server running there is no manifest at all, and the assets
     * are served by Vite itself.
     */
    public function test_a_custom_hot_file_still_emits_the_asset_tags(): void
    {
        file_put_contents($this->publicPath . '/vite-dev-server', 'http://127.0.0.1:5173');
        $this->app->make(Vite::class)->useHotFile($this->publicPath . '/vite-dev-server');

        $content = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('http://127.0.0.1:5173/@vite/client', $content);
        $this->assertStringContainsString('http://127.0.0.1:5173/resources/scss/auth.scss', $content);
        $this->assertStringContainsString('http://127.0.0.1:5173/resources/js/app.js', $content);
    }

    /**
     * A manifest that exists but does not list the package's entries is the
     * common case straight after `npm run build` on an application that has
     * not added them to vite.config.js. The Vite helper throws there too.
     */
    public function test_a_manifest_without_the_entries_does_not_break_the_page(): void
    {
        $this->writeManifest('build', [
            'resources/css/app.css' => [
                'file' => 'assets/app-test.css',
                'src' => 'resources/css/app.css',
                'isEntry' => true,
            ],
        ]);

        $content = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('assets/app-test.css', $content);
    }
}
