<?php

/**
 * Shared helpers for the consumer install/upgrade harnesses.
 *
 * Deliberately dependency-free: these scripts run before (and outside) the
 * package's own vendor tree.
 *
 * Every filesystem operation is scoped to a workspace this process created.
 * There is no broad delete anywhere in this file.
 */

declare(strict_types=1);

function heading(string $text): void
{
    fwrite(STDOUT, PHP_EOL . '=== ' . $text . ' ===' . PHP_EOL);
}

function note(string $text): void
{
    fwrite(STDOUT, '    ' . $text . PHP_EOL);
}

function fail(string $message): never
{
    fwrite(STDERR, PHP_EOL . 'FAIL: ' . $message . PHP_EOL);

    // On GitHub the run summary shows only "Process completed with exit
    // code 1", and reading the step log requires repository admin rights.
    // A failed gate nobody can diagnose from the run page costs a whole
    // round trip, so the reason is emitted as an annotation as well.
    // Encoded in PHP rather than in YAML: the shell quoting this would
    // need is exactly what once wrote literal control bytes into the
    // workflow.
    if (getenv('GITHUB_ACTIONS') === 'true') {
        $encoded = str_replace('%', '%25', substr($message, 0, 3000));
        $encoded = str_replace([chr(13) . chr(10), chr(13), chr(10)], '%0A', $encoded);

        fwrite(STDOUT, '::error title=Harness gate failed::' . $encoded . PHP_EOL);
    }

    exit(1);
}

/** @param callable():void $work */
function step(string $label, callable $work): void
{
    fwrite(STDOUT, '--> ' . $label . ' ... ');
    $started = hrtime(true);

    try {
        $work();
    } catch (Throwable $exception) {
        fwrite(STDOUT, 'FAILED' . PHP_EOL);
        fail($label . ': ' . $exception->getMessage());
    }

    fwrite(STDOUT, sprintf('ok (%.1fs)%s', (hrtime(true) - $started) / 1e9, PHP_EOL));
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function assertContains(string $haystack, string $needle, string $message, bool $caseInsensitive = false): void
{
    $found = $caseInsensitive
        ? stripos($haystack, $needle) !== false
        : str_contains($haystack, $needle);

    if (!$found) {
        throw new RuntimeException($message);
    }
}

function assertNotContains(string $haystack, string $needle, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message);
    }
}

function composerBinary(): string
{
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    foreach (['composer', 'composer.phar', 'C:/composer/composer.phar'] as $candidate) {
        $probe = str_ends_with($candidate, '.phar')
            ? escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($candidate)
            : escapeshellarg($candidate);

        exec($probe . ' --version 2>&1', $out, $code);

        if ($code === 0) {
            return $resolved = $probe;
        }
    }

    fail('Composer was not found on PATH.');
}

function run(string $command, ?string $cwd = null): string
{
    $full = $cwd !== null
        ? 'cd ' . escapeshellarg($cwd) . ' && ' . $command
        : $command;

    exec($full . ' 2>&1', $output, $code);
    $text = implode(PHP_EOL, $output);

    if ($code !== 0) {
        throw new RuntimeException("command failed (exit {$code}): {$command}" . PHP_EOL . $text);
    }

    return $text;
}

function composer(string $arguments, ?string $cwd = null): string
{
    return run(composerBinary() . ' ' . $arguments, $cwd);
}

function artisan(string $arguments, string $app): string
{
    return run(escapeshellarg(PHP_BINARY) . ' artisan ' . $arguments, $app);
}
/**
 * Run an artisan command that is expected to report a problem.
 *
 * Some gates ARE the non-zero exit -- the upgrade audit fails closed when it
 * finds stale published views. artisan() throws on any non-zero exit, so a
 * caller asserting on that behaviour could never reach its assertions.
 *
 * @return array{code:int, output:string}
 */
function artisanAllowingFailure(string $arguments, string $app): array
{
    $command = 'cd ' . escapeshellarg($app) . ' && '
        . escapeshellarg(PHP_BINARY) . ' artisan ' . $arguments . ' 2>&1';

    exec($command, $output, $code);

    return ['code' => $code, 'output' => implode(PHP_EOL, $output)];
}


/**
 * Create an isolated workspace under the system temp directory.
 */
function makeWorkspace(string $prefix): string
{
    $path = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(6));

    if (is_dir($path) || file_exists($path)) {
        fail("Workspace {$path} already exists; refusing to reuse it.");
    }

    if (!mkdir($path, 0700, true)) {
        fail("Unable to create workspace {$path}");
    }

    return str_replace('\\', '/', realpath($path) ?: $path);
}

/**
 * Remove only a directory this process created, and only when it still looks
 * like the workspace we made. A harness must never be able to delete anything
 * else, whatever state it failed in.
 */
function removeWorkspace(string $path): void
{
    $real = realpath($path);
    $tmp = realpath(sys_get_temp_dir());

    if ($real === false || $tmp === false) {
        note('Workspace already gone.');
        return;
    }

    $real = str_replace('\\', '/', $real);
    $tmp = str_replace('\\', '/', $tmp);

    if (!str_starts_with($real, $tmp . '/')) {
        fail("Refusing to remove {$real}: it is outside the temp directory.");
    }

    if (!preg_match('#/ln-starter-(consumer|upgrade)-[0-9a-f]{12}$#', $real)) {
        fail("Refusing to remove {$real}: it does not look like a harness workspace.");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($real);
    note("Workspace removed: {$real}");
}

/**
 * @param array<string, string> $values
 */
function writeEnv(string $app, array $values): void
{
    $path = $app . '/.env';
    $env = file_exists($path) ? (string) file_get_contents($path) : '';

    foreach ($values as $key => $value) {
        $line = $key . '=' . (str_contains($value, ' ') ? '"' . $value . '"' : $value);

        if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $env)) {
            $env = (string) preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $line, $env);
        } else {
            $env .= PHP_EOL . $line;
        }
    }

    file_put_contents($path, $env);
}

/**
 * Bring a freshly created skeleton to a bootable state.
 *
 * MUST run before `composer require`. Requiring the package triggers package
 * discovery, which boots the service provider, which validates configuration
 * and needs APP_KEY (or an explicit pseudonym key) to exist. With
 * --no-scripts there is no .env and no APP_KEY, so the very first require
 * would fail before a single assertion ran.
 *
 * @param array<string, string> $extra
 */
function bootstrapSkeleton(string $app, array $extra = []): void
{
    if (!file_exists($app . '/.env') && file_exists($app . '/.env.example')) {
        copy($app . '/.env.example', $app . '/.env');
    }

    if (!file_exists($app . '/.env')) {
        file_put_contents($app . '/.env', "APP_NAME=Harness
APP_ENV=local
APP_DEBUG=true
");
    }

    if (!is_dir($app . '/database')) {
        mkdir($app . '/database', 0755, true);
    }

    $sqlite = $app . '/database/database.sqlite';
    if (!file_exists($sqlite)) {
        touch($sqlite);
    }

    writeEnv($app, array_merge([
        'APP_ENV' => 'local',
        'APP_DEBUG' => 'true',
        'APP_URL' => 'http://localhost',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $sqlite,
        'SESSION_DRIVER' => 'file',
        'QUEUE_CONNECTION' => 'database',
        'MAIL_MAILER' => 'log',
        // Both secrets exist before the provider can ever boot.
        'LN_AUTH_PEPPER' => 'base64:' . base64_encode(random_bytes(32)),
        'LN_SECURITY_PSEUDONYM_KEY' => 'base64:' . base64_encode(random_bytes(32)),
    ], $extra));

    // --no-scripts skips key:generate, so set APP_KEY directly.
    writeEnv($app, ['APP_KEY' => 'base64:' . base64_encode(random_bytes(32))]);
}

/** @return array<string, string> filename => sha1 */
function fileInventory(string $directory): array
{
    $inventory = [];

    foreach (glob(rtrim($directory, '/\\') . '/*') ?: [] as $file) {
        if (is_file($file)) {
            $inventory[basename($file)] = sha1_file($file) ?: '';
        }
    }

    ksort($inventory);

    return $inventory;
}

/**
 * Boot `artisan serve` on an ephemeral port, issue one request, shut it down.
 *
 * @return array{status:int, body:string, headers:list<string>}
 */
function serveRequest(string $app, string $path, string $method = 'GET'): array
{
    $port = randomFreePort();
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open(
        escapeshellarg(PHP_BINARY) . ' artisan serve --port=' . $port . ' --no-interaction',
        $descriptors,
        $pipes,
        $app
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start artisan serve.');
    }

    try {
        $deadline = microtime(true) + 20;
        $body = '';
        $status = 0;

        while (microtime(true) < $deadline) {
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'ignore_errors' => true,
                    'timeout' => 5,
                    // Never follow a redirect: an assertion that a page
                    // answers 200 must not be satisfiable by a 302 to some
                    // other page, and a tombstone's own status is the thing
                    // under test.
                    'follow_location' => 0,
                    'max_redirects' => 1,
                    'header' => "Accept: text/html\r\n",
                ],
            ]);

            $response = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);

            if ($response !== false) {
                $body = $response;
                $status = 0;

                foreach ($http_response_header ?? [] as $header) {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                        $status = (int) $m[1];
                    }
                }

                return ['status' => $status, 'body' => $body, 'headers' => $http_response_header ?? []];
            }

            usleep(200_000);
        }

        throw new RuntimeException("No response from {$method} {$path} within 20s.");
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_terminate($process);
        proc_close($process);
    }
}

/**
 * A one-line, log-safe description of a harness response.
 *
 * The throwaway application runs with APP_DEBUG=true, so its error pages can
 * carry environment values. This never returns raw page content: only the
 * status and the document title (where Laravel puts the exception message),
 * with anything key-shaped redacted, because the caller hands this to a
 * public CI annotation.
 *
 * @param array{status:int, body:string, headers?:list<string>} $response
 */
function describeResponse(array $response): string
{
    $body = $response['body'];

    $summary = preg_match('#<title[^>]*>(.*?)</title>#si', $body, $match) === 1
        ? $match[1]
        : substr(strip_tags($body), 0, 200);

    $summary = (string) preg_replace('/\s+/', ' ', trim($summary));
    $summary = (string) preg_replace('/base64:[A-Za-z0-9+\/=]{8,}/', 'base64:[redacted]', $summary);

    return sprintf('HTTP %d - %s', $response['status'], $summary === '' ? '(empty body)' : $summary);
}

/** @return array{status:int, body:string, headers:list<string>} */
function serveAndGet(string $app, string $path): array
{
    return serveRequest($app, $path, 'GET');
}

/** @return array{status:int, body:string, headers:list<string>} */
function serveAndPost(string $app, string $path): array
{
    return serveRequest($app, $path, 'POST');
}

function randomFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($socket === false) {
        throw new RuntimeException("Unable to reserve a port: {$errstr}");
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
}
