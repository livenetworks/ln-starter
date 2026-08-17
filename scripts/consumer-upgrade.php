<?php

/**
 * Upgrade-path harness.
 *
 * Builds a consumer application that looks like a *previous* installation —
 * historical users migrations, published auth v1 views, a legacy
 * magic_link_tokens table with real rows, and a config file written before the
 * nested auth/logging keys existed — then installs the current package on top.
 *
 * The point is what must NOT happen: consumer files must not be overwritten,
 * legacy data must not be destroyed, and the optional audit table must stay
 * opt-in.
 *
 * Usage: php scripts/consumer-upgrade.php --laravel=13 [--keep]
 */

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

$options = getopt('', ['laravel:', 'keep']);
$laravel = $options['laravel'] ?? '13';
$keep = array_key_exists('keep', $options);

if (!in_array($laravel, ['12', '13'], true)) {
    fail("Unsupported Laravel lane: {$laravel}.");
}

$package = dirname(__DIR__);
$workspace = makeWorkspace('ln-starter-upgrade');
$app = $workspace . '/app';

heading("Upgrade path — Laravel {$laravel}");
note("Workspace: {$workspace}");

/** Content markers we can assert survived the upgrade untouched. */
$consumerMigrationMarker = '// consumer-owned users migration — must survive upgrade';
$consumerViewMarker = '{{-- consumer-modified login view --}}';

try {
    step('Create the base application', function () use ($laravel, $app): void {
        composer(sprintf(
            'create-project laravel/laravel %s "^%s.0" --no-interaction --no-scripts --prefer-dist',
            escapeshellarg($app),
            $laravel
        ));
    });

    step('Simulate a historical installation', function () use ($app, $consumerMigrationMarker, $consumerViewMarker): void {
        // Two historical users migrations: the Laravel default plus one the
        // consumer wrote themselves.
        $migrations = $app . '/database/migrations';
        $custom = $migrations . '/2019_01_01_000000_create_users_table.php';
        file_put_contents($custom, "<?php\n\n{$consumerMigrationMarker}\nreturn new class extends Illuminate\\Database\\Migrations\\Migration {\n    public function up(): void {}\n    public function down(): void {}\n};\n");

        // Published (and then edited) auth v1 views.
        $views = $app . '/resources/views/vendor/ln-starter/auth';
        mkdir($views, 0755, true);
        file_put_contents($views . '/login.blade.php', "{$consumerViewMarker}\n<form method=\"POST\">@csrf</form>\n");
        file_put_contents($views . '/magic_wait.blade.php', "<script>setInterval(() => fetch('/magic/status'), 2000)</script>\n");

        // A config file from before the nested auth/logging keys existed.
        file_put_contents($app . '/config/ln-starter.php', "<?php\n\nreturn [\n    'layout' => 'layouts._app',\n    'auth' => [\n        'enabled' => true,\n        'token_expiry' => 20,\n    ],\n];\n");
    });

    step('Install the current package over it', function () use ($app, $package): void {
        composer('config repositories.ln-starter path ' . escapeshellarg(str_replace('\\', '/', $package)), $app);
        composer('config minimum-stability dev', $app);
        composer('config prefer-stable true', $app);
        composer('require livenetworks/ln-starter:* --no-interaction', $app);
    });

    step('Configure the upgraded application', function () use ($app): void {
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
    });

    step('Recursive config merge keeps old published config usable', function () use ($app): void {
        $dump = artisan('tinker --execute="echo json_encode([config(\'ln-starter.auth.token_expiry\'), config(\'ln-starter.auth.peppers.current\'), config(\'ln-starter.logging.enabled\')]);"', $app);

        // The consumer's own value survives, and keys they never had appear.
        assertContains($dump, '20', 'the consumer token_expiry was lost');
        assertContains($dump, 'v1', 'the new pepper defaults were not merged in');
    });

    step('Create a legacy magic_link_tokens table with rows', function () use ($app): void {
        $db = $app . '/database/database.sqlite';
        $pdo = new PDO('sqlite:' . $db);
        $pdo->exec('CREATE TABLE magic_link_tokens (id INTEGER PRIMARY KEY, user_id INTEGER, token TEXT, approved INTEGER DEFAULT 0, approved_at TEXT NULL, expires_at TEXT, created_at TEXT, updated_at TEXT)');

        $now = date('Y-m-d H:i:s');
        $future = date('Y-m-d H:i:s', time() + 3600);
        $past = date('Y-m-d H:i:s', time() - 86400 * 3);

        $pdo->exec("INSERT INTO magic_link_tokens VALUES (1, 1, 'pending-token', 0, NULL, '{$future}', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO magic_link_tokens VALUES (2, 1, 'approved-token', 1, '{$now}', '{$future}', '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO magic_link_tokens VALUES (3, 1, 'expired-token', 0, NULL, '{$past}', '{$past}', '{$past}')");
    });

    step('Upgrade audit reports the stale published views', function () use ($app): void {
        $output = artisan('ln-starter:auth-v2-audit', $app);
        assertContains($output, 'magic_wait', 'the audit did not flag the stale polling view');
    });

    step('Installer refuses to clobber consumer migrations', function () use ($app, $consumerMigrationMarker): void {
        $before = fileInventory($app . '/database/migrations');
        artisan('ln-starter:install --no-interaction', $app);
        $after = fileInventory($app . '/database/migrations');

        $custom = $app . '/database/migrations/2019_01_01_000000_create_users_table.php';
        assertContains((string) file_get_contents($custom), $consumerMigrationMarker, 'the consumer users migration was overwritten');

        foreach ($before as $name => $hash) {
            if (isset($after[$name])) {
                assertSame($hash, $after[$name], "migration {$name} was modified in place");
            }
        }
    });

    step('Installer does not replace consumer views without --force', function () use ($app, $consumerViewMarker): void {
        $login = $app . '/resources/views/vendor/ln-starter/auth/login.blade.php';
        assertContains((string) file_get_contents($login), $consumerViewMarker, 'the consumer view was replaced');
    });

    step('Migrate the upgraded application', function () use ($app): void {
        artisan('migrate --force --no-interaction', $app);
    });

    step('The audit table stays absent while the sink is disabled', function () use ($app): void {
        $pdo = new PDO('sqlite:' . $app . '/database/database.sqlite');
        $found = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ln_security_audit_events'")->fetchColumn();

        assertSame(false, $found, 'the opt-in audit table was created without being enabled');
    });

    step('Enabling the sink and publishing its migration creates the table', function () use ($app): void {
        writeEnv($app, ['LN_SECURITY_AUDIT_DB' => 'true']);
        artisan('vendor:publish --tag=ln-starter-security-migrations --no-interaction', $app);
        artisan('migrate --force --no-interaction', $app);

        $pdo = new PDO('sqlite:' . $app . '/database/database.sqlite');
        $found = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ln_security_audit_events'")->fetchColumn();

        assertSame('ln_security_audit_events', $found, 'the audit table was not created after opting in');
    });

    step('Legacy tombstones answer 410 without issuing credentials', function () use ($app): void {
        foreach (['/magic/wait', '/magic/status'] as $path) {
            $response = serveAndGet($app, $path);

            assertTrue(
                in_array($response['status'], [302, 410], true),
                "{$path} returned {$response['status']}, expected 410 or a redirect"
            );
            assertNotContains($response['body'], 'token', "{$path} response mentions a token");
        }
    });

    step('Legacy cleanup retains active rows', function () use ($app): void {
        artisan('magic-link-tokens:cleanup --hours=24', $app);

        $pdo = new PDO('sqlite:' . $app . '/database/database.sqlite');
        $remaining = (int) $pdo->query('SELECT COUNT(*) FROM magic_link_tokens')->fetchColumn();

        // The fresh pending row must survive; only aged terminal rows go.
        assertTrue($remaining >= 1, 'cleanup deleted an active legacy token');

        $pending = (int) $pdo->query("SELECT COUNT(*) FROM magic_link_tokens WHERE token = 'pending-token'")->fetchColumn();
        assertSame(1, $pending, 'the fresh pending legacy token was deleted');
    });

    step('Caches build on the upgraded application', function () use ($app): void {
        artisan('config:cache', $app);
        artisan('route:cache', $app);
        artisan('view:cache', $app);
        artisan('route:list --json', $app);
    });

    step('Readiness passes after the upgrade', function () use ($app): void {
        $output = artisan('ln-starter:auth-v2-readiness', $app);
        assertContains($output, 'Readiness checks passed', 'readiness failed after upgrade');
        assertNotContains($output, 'base64:', 'readiness leaked key material');
    });

    step('No token, code or address reached the upgrade logs', function () use ($app): void {
        foreach (glob($app . '/storage/logs/*.log') ?: [] as $log) {
            $contents = (string) file_get_contents($log);

            foreach (['pending-token', 'approved-token', 'LN_AUTH_PEPPER', 'base64:'] as $needle) {
                assertNotContains($contents, $needle, "log contains {$needle}");
            }
        }
    });

    heading('Upgrade path PASSED for Laravel ' . $laravel);
} finally {
    if ($keep) {
        note("Workspace kept at {$workspace}");
    } else {
        removeWorkspace($workspace);
    }
}

exit(0);
