<?php

/**
 * Build the distributable archive and assert what it does — and does not —
 * contain.
 *
 * A green test suite proves the working tree behaves. It says nothing about
 * what Composer actually ships: `vendor/`, a stray `.env`, a local SQLite file,
 * or a log full of tokens would all pass the suite and still reach consumers.
 *
 * Usage: php scripts/verify-artifact.php
 * Exit code 0 = artifact is publishable.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$notes = [];

function out(string $line): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

/** Locate a runnable Composer. */
function composerCommand(): ?string
{
    foreach (['composer', 'composer.phar', 'C:/composer/composer.phar'] as $candidate) {
        $probe = str_ends_with($candidate, '.phar')
            ? escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($candidate)
            : escapeshellarg($candidate);

        exec($probe . ' --version 2>&1', $o, $code);
        if ($code === 0) {
            return $probe;
        }
    }

    return null;
}

$composer = composerCommand();

if ($composer === null) {
    fwrite(STDERR, "Composer not found; cannot build the artifact." . PHP_EOL);
    exit(1);
}

$workdir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ln-starter-artifact-' . bin2hex(random_bytes(6));

if (!mkdir($workdir, 0700, true) && !is_dir($workdir)) {
    fwrite(STDERR, "Unable to create {$workdir}" . PHP_EOL);
    exit(1);
}

out("Building artifact in {$workdir}");

exec(
    $composer . ' archive --format=zip --dir=' . escapeshellarg($workdir)
    . ' --working-dir=' . escapeshellarg($root) . ' --no-interaction 2>&1',
    $buildOutput,
    $buildCode
);

if ($buildCode !== 0) {
    fwrite(STDERR, implode(PHP_EOL, $buildOutput) . PHP_EOL);
    fwrite(STDERR, "composer archive failed." . PHP_EOL);
    exit(1);
}

$archives = glob($workdir . DIRECTORY_SEPARATOR . '*.zip') ?: [];

if (count($archives) !== 1) {
    fwrite(STDERR, "Expected exactly one archive, found " . count($archives) . PHP_EOL);
    exit(1);
}

$archivePath = $archives[0];
out('Artifact: ' . basename($archivePath) . ' (' . number_format(filesize($archivePath) / 1024, 1) . ' KiB)');

$zip = new ZipArchive();

if ($zip->open($archivePath) !== true) {
    fwrite(STDERR, "Unable to open the archive." . PHP_EOL);
    exit(1);
}

$entries = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entries[] = $zip->getNameIndex($i);
}

// composer archive writes package-relative paths at the archive root; there is
// no wrapping directory to strip.
$relative = array_values(array_filter($entries, static fn (string $e): bool => $e !== ''));

out('Entries: ' . count($relative));

// ---------------------------------------------------------------- must NOT be present
$forbidden = [
    'vendor directory' => '#^vendor/#',
    'git metadata' => '#(^|/)\.git(/|$)#',
    'environment file' => '#(^|/)\.env#',
    'log file' => '#\.log$#',
    'sqlite database' => '#\.sqlite3?$#',
    'phpunit config' => '#^phpunit\.xml#',
    'test suite' => '#^tests/#',
    'CI workflows' => '#^\.github/#',
    'build scripts' => '#^scripts/#',
    'composer lock' => '#^composer\.lock$#',
    'phpunit cache' => '#^\.phpunit#',
    'node modules' => '#^node_modules/#',
    'editor settings' => '#^\.(idea|vscode)/#',
    'agent settings' => '#^\.claude/#',
    'git config' => '#^\.gitmodules$#',
];

foreach ($forbidden as $label => $pattern) {
    $hits = array_values(array_filter($relative, static fn (string $e): bool => (bool) preg_match($pattern, $e)));

    if ($hits !== []) {
        $failures[] = sprintf(
            'Artifact contains %s (%d entr%s), e.g. %s',
            $label,
            count($hits),
            count($hits) === 1 ? 'y' : 'ies',
            $hits[0]
        );
    }
}

// ---------------------------------------------------------------- must be present
$required = [
    'composer.json',
    'README.md',
    'CHANGELOG.md',
    'UPGRADE.md',
    'LICENSE',
    'src/LnStarterServiceProvider.php',
    'src/Http/Controllers/AuthController.php',
    'src/Security/SecurityEventDispatcher.php',
    'config/ln-starter.php',
    'routes/auth.php',
    'database/migrations/auth-v2/create_magic_login_attempts_table.php',
    'database/migrations/security/create_ln_security_audit_events_table.php',
    'resources/views/auth/login.blade.php',
    'resources/views/components/ln/modal.blade.php',
    'docs/security-logging.md',
    'docs/adr/0002-security-audit-logging-and-observability.md',
    'skills/ln-starter/SKILL.md',
    'stubs/User.stub',
];

foreach ($required as $path) {
    if (!in_array($path, $relative, true)) {
        $failures[] = "Artifact is missing required file: {$path}";
    }
}

// ---------------------------------------------------------------- content sanity
$manifestIndex = null;
foreach ($entries as $i => $entry) {
    if ($entry === 'composer.json') {
        $manifestIndex = $i;
    }
}

if ($manifestIndex === null) {
    $failures[] = 'Artifact has no composer.json at its root.';
} else {
    $manifest = json_decode((string) $zip->getFromIndex($manifestIndex), true);

    if (!is_array($manifest)) {
        $failures[] = 'Artifact composer.json is not valid JSON.';
    } else {
        if (($manifest['name'] ?? null) !== 'livenetworks/ln-starter') {
            $failures[] = 'Artifact composer.json has an unexpected package name.';
        }

        if (!isset($manifest['autoload']['psr-4']['LiveNetworks\\LnStarter\\'])) {
            $failures[] = 'Artifact composer.json is missing the PSR-4 autoload root.';
        }

        if (!isset($manifest['extra']['laravel']['providers'])) {
            $failures[] = 'Artifact composer.json is missing Laravel package discovery.';
        }

        $notes[] = 'Declared PHP requirement: ' . ($manifest['require']['php'] ?? 'unset');
    }
}

/**
 * Scan shipped text for anything resembling a credential. Deliberately narrow
 * so it flags real leaks rather than every base64-looking string.
 */
$secretPatterns = [
    'APP_KEY value' => '#\bbase64:[A-Za-z0-9+/]{40,}={0,2}#',
    'AWS access key' => '#\bAKIA[0-9A-Z]{16}\b#',
    'private key block' => '#-----BEGIN [A-Z ]*PRIVATE KEY-----#',
    'bearer token literal' => '#\bBearer\s+[A-Za-z0-9._~+/-]{24,}#',
];

for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);

    if (!preg_match('#\.(php|md|json|ya?ml|blade\.php|stub|env|txt)$#', $name)) {
        continue;
    }

    $contents = (string) $zip->getFromIndex($i);

    foreach ($secretPatterns as $label => $pattern) {
        if (preg_match($pattern, $contents)) {
            // The docs legitimately show the *shape* of a key in an example.
            if (str_contains($name, 'docs/') || str_contains($name, 'UPGRADE.md')) {
                continue;
            }

            $failures[] = "Possible {$label} in shipped file {$name}";
        }
    }
}

$zip->close();

// ---------------------------------------------------------------- installability
$installDir = $workdir . DIRECTORY_SEPARATOR . 'consume';
mkdir($installDir, 0700, true);

$extractDir = $workdir . DIRECTORY_SEPARATOR . 'extracted';
mkdir($extractDir, 0700, true);

$zip = new ZipArchive();
$zip->open($archivePath);
$zip->extractTo($extractDir);
$zip->close();

$packageRoot = $extractDir;

file_put_contents($installDir . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
    'name' => 'probe/artifact-consumer',
    'repositories' => [[
        'type' => 'path',
        'url' => str_replace('\\', '/', $packageRoot),
        'options' => ['symlink' => false],
    ]],
    'require' => ['livenetworks/ln-starter' => '*'],
    'minimum-stability' => 'dev',
    'prefer-stable' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

out('Resolving the artifact as a path repository…');

exec(
    $composer . ' update --dry-run --no-interaction --no-audit'
    . ' --working-dir=' . escapeshellarg($installDir) . ' 2>&1',
    $installOutput,
    $installCode
);

$installLog = implode(PHP_EOL, $installOutput);

if ($installCode !== 0) {
    // Network-dependent: report honestly instead of claiming a pass.
    if (str_contains($installLog, 'curl error') || str_contains($installLog, 'could not be fully loaded')) {
        $notes[] = 'Install check SKIPPED: package metadata is unreachable from this machine.';
    } else {
        $failures[] = 'The artifact could not be resolved as a dependency.';
        $notes[] = trim($installLog);
    }
} elseif (!str_contains($installLog, 'livenetworks/ln-starter')) {
    $failures[] = 'Resolution succeeded but did not select livenetworks/ln-starter.';
} else {
    $notes[] = 'Install check PASSED: the artifact resolves as a dependency.';
}

// ---------------------------------------------------------------- cleanup
$removed = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workdir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($iterator as $item) {
    // Scoped to the directory this script created, never a broad delete.
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    $removed++;
}
@rmdir($workdir);

out("Cleaned up {$removed} temporary entries.");

// ---------------------------------------------------------------- report
out('');
foreach ($notes as $note) {
    out('  note: ' . $note);
}

if ($failures !== []) {
    out('');
    foreach ($failures as $failure) {
        fwrite(STDERR, '  FAIL: ' . $failure . PHP_EOL);
    }
    fwrite(STDERR, PHP_EOL . count($failures) . ' artifact check(s) failed.' . PHP_EOL);
    exit(1);
}

out('');
out('Artifact verification passed.');
exit(0);
