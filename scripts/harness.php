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
 * @return array{status:int, body:string}
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

                return ['status' => $status, 'body' => $body];
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

/** @return array{status:int, body:string} */
function serveAndGet(string $app, string $path): array
{
    return serveRequest($app, $path, 'GET');
}

/** @return array{status:int, body:string} */
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
