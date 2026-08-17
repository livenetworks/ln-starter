<?php

/**
 * Fresh-consumer installation harness.
 *
 * Creates a throwaway Laravel application, installs this package from a
 * Composer path repository, and drives the full install path a real consumer
 * follows. The package's own test suite runs inside Testbench, which is not the
 * same thing: Testbench never exercises package discovery, vendor:publish, the
 * installer command, or config/route/view caching in a real skeleton.
 *
 * Usage: php scripts/consumer-install.php --laravel=13 [--keep]
 *
 * Every path it touches is created by this script inside the system temp
 * directory. It never writes to an existing application and never deletes
 * anything outside the directory it made.
 */

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

$options = getopt('', ['laravel:', 'keep']);
$laravel = $options['laravel'] ?? '13';
$keep = array_key_exists('keep', $options);

if (!in_array($laravel, ['12', '13'], true)) {
    fail("Unsupported Laravel lane: {$laravel}. Supported production lanes are 12 and 13.");
}

$package = dirname(__DIR__);
$workspace = makeWorkspace('ln-starter-consumer');
$app = $workspace . '/app';

heading("Fresh consumer install — Laravel {$laravel}");
note("Package:   {$package}");
note("Workspace: {$workspace}");

try {
    step('Create a clean Laravel application', function () use ($laravel, $app): void {
        composer(sprintf(
            'create-project laravel/laravel %s "^%s.0" --no-interaction --no-scripts --prefer-dist',
            escapeshellarg($app),
            $laravel
        ));
    });

    step('Point the application at the package path repository', function () use ($app, $package): void {
        composer('config repositories.ln-starter path ' . escapeshellarg(str_replace('\\', '/', $package)), $app);
        composer('config minimum-stability dev', $app);
        composer('config prefer-stable true', $app);
    });

    step('Require the package', function () use ($app): void {
        composer('require livenetworks/ln-starter:* --no-interaction', $app);
    });

    step('Confirm package discovery', function () use ($app): void {
        $manifest = $app . '/bootstrap/cache/packages.php';
        assertTrue(file_exists($manifest), 'packages.php manifest was not generated');
        assertContains(
            (string) file_get_contents($manifest),
            'LnStarterServiceProvider',
            'the service provider was not auto-discovered'
        );
    });

    step('Publish the config', function () use ($app): void {
        artisan('vendor:publish --tag=ln-starter-config --no-interaction', $app);
        assertTrue(file_exists($app . '/config/ln-starter.php'), 'config was not published');
    });

    step('Publish the auth v2 migration', function () use ($app): void {
        artisan('vendor:publish --tag=ln-starter-migrations --no-interaction', $app);
        assertTrue(
            glob($app . '/database/migrations/*create_magic_login_attempts_table.php') !== [],
            'the auth v2 migration was not published'
        );
    });

    step('Publish the optional security-audit migration', function () use ($app): void {
        artisan('vendor:publish --tag=ln-starter-security-migrations --no-interaction', $app);
        assertTrue(
            glob($app . '/database/migrations/*create_ln_security_audit_events_table.php') !== [],
            'the audit migration was not published'
        );
    });

    step('Publish the views', function () use ($app): void {
        artisan('vendor:publish --tag=ln-starter-views --no-interaction', $app);
        assertTrue(
            is_dir($app . '/resources/views/vendor/ln-starter/auth'),
            'auth views were not published'
        );
    });

    step('Enable the auth module on SQLite', function () use ($app): void {
        touch($app . '/database/database.sqlite');
        writeEnv($app, [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $app . '/database/database.sqlite',
            'SESSION_DRIVER' => 'file',
            'QUEUE_CONNECTION' => 'database',
            'MAIL_MAILER' => 'log',
            'LN_AUTH_PEPPER' => 'base64:' . base64_encode(random_bytes(32)),
            'LN_SECURITY_PSEUDONYM_KEY' => 'base64:' . base64_encode(random_bytes(32)),
        ]);

        $configPath = $app . '/config/ln-starter.php';
        $config = (string) file_get_contents($configPath);
        $config = preg_replace("/'enabled'(\s*)=>(\s*)false/", "'enabled'\$1=>\$2true", $config, 1);
        file_put_contents($configPath, $config);
    });

    step('Run the installer', function () use ($app): void {
        $output = artisan('ln-starter:install --no-interaction', $app);
        assertNotContains($output, 'installation stopped', 'the installer aborted');
    });

    step('Run the installer again (idempotency)', function () use ($app): void {
        $before = fileInventory($app . '/database/migrations');
        $output = artisan('ln-starter:install --no-interaction', $app);
        $after = fileInventory($app . '/database/migrations');

        assertSame($before, $after, 'a second install changed the migrations directory');
        assertNotContains($output, 'installation stopped', 'the second install aborted');
    });

    step('Migrate', function () use ($app): void {
        artisan('migrate --force --no-interaction', $app);
    });

    step('Cache config, routes and views', function () use ($app): void {
        artisan('config:cache', $app);
        artisan('route:cache', $app);
        artisan('view:cache', $app);
    });

    step('List routes with caches warm', function () use ($app): void {
        $routes = artisan('route:list --json', $app);

        foreach (['login', 'login.magic-link', 'auth.magic.code', 'logout'] as $name) {
            assertContains($routes, $name, "route {$name} is missing");
        }
    });

    step('Confirm no secret-bearing route is cached by name', function () use ($app): void {
        $cached = $app . '/bootstrap/cache/routes-v7.php';

        if (file_exists($cached)) {
            $contents = (string) file_get_contents($cached);
            // The wildcard token segment must stay a placeholder, never a value.
            assertContains($contents, '{token}', 'the link route lost its token placeholder');
        }
    });

    step('Readiness command', function () use ($app): void {
        $output = artisan('ln-starter:auth-v2-readiness', $app);
        assertContains($output, 'Readiness checks passed', 'readiness did not pass');
        assertNotContains($output, 'base64:', 'readiness output leaked key material');
    });

    step('HTTP smoke test', function () use ($app): void {
        $login = serveAndGet($app, '/login');
        assertContains($login['body'], 'csrf', 'the login form has no CSRF token', true);

        $code = serveAndGet($app, '/auth/magic/code');
        assertSame(200, $code['status'], 'the code form did not render');

        $logout = serveAndPost($app, '/logout');
        assertTrue(
            in_array($logout['status'], [302, 419], true),
            'logout should redirect or reject without CSRF, got ' . $logout['status']
        );
    });

    step('Confirm the logout component carries CSRF', function () use ($app): void {
        $component = $app . '/vendor/livenetworks/ln-starter/resources/views/components/ln/logout-form.blade.php';

        if (file_exists($component)) {
            assertContains((string) file_get_contents($component), '@csrf', 'the logout form has no @csrf');
        }

        $modal = $app . '/vendor/livenetworks/ln-starter/resources/views/components/ln/modal.blade.php';
        assertContains((string) file_get_contents($modal), '@csrf', 'the modal form has no @csrf');
    });

    step('Confirm no credential reached the application log', function () use ($app): void {
        foreach (glob($app . '/storage/logs/*.log') ?: [] as $log) {
            $contents = (string) file_get_contents($log);

            foreach (['LN_AUTH_PEPPER', 'base64:', 'Bearer '] as $needle) {
                assertNotContains($contents, $needle, "log {$log} contains {$needle}");
            }
        }
    });

    heading('Fresh consumer install PASSED for Laravel ' . $laravel);
} finally {
    if ($keep) {
        note("Workspace kept at {$workspace}");
    } else {
        removeWorkspace($workspace);
    }
}

exit(0);
