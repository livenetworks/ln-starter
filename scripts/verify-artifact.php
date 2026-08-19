<?php

/**
 * Build the distributable archive and assert what it does — and does not —
 * contain.
 *
 * A green test suite proves the working tree behaves. It says nothing about
 * what Composer actually ships: `vendor/`, a stray `.env`, a local SQLite file,
 * or a log full of tokens would all pass the suite and still reach consumers.
 *
 * Usage: php scripts/verify-artifact.php [--allow-offline]
 *
 * Exit code 0 means the artifact is publishable, which includes having been
 * installed from the archive. Without network access that cannot be proven, so
 * the script FAILS rather than reporting a pass — unless --allow-offline is
 * passed explicitly, which downgrades it to a clearly-labelled partial run.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$notes = [];

$options = getopt('', ['allow-offline', 'keep-extracted', 'output-path-file:']);
$allowOffline = array_key_exists('allow-offline', $options);

// CI extracts once and hands the directory to the consumer harness, so that
// package discovery is exercised against the published archive.
$keepExtracted = array_key_exists('keep-extracted', $options);

// Machine-readable handoff. Parsing a human-readable log for a path is how a
// stray character in the log format silently becomes a broken CI step.
$outputPathFile = $options['output-path-file'] ?? null;

if ($outputPathFile !== null && !$keepExtracted) {
    // Without --keep-extracted the directory is deleted before this
    // script returns, so the path would name something that no longer
    // exists. Refuse rather than hand back a dangling path.
    fwrite(STDERR, '--output-path-file requires --keep-extracted.' . PHP_EOL);
    exit(1);
}

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

/**
 * Documentation legitimately shows the *shape* of an APP_KEY-style value. That
 * is the only exemption: a real AWS key, bearer token, or private key block in
 * docs/ would still be shipped, so the previous blanket skip for anything under
 * docs/ was too wide.
 */
$documentedPlaceholders = [
    'base64:...',
    'base64:<32-random-bytes>',
    'base64:' . str_repeat('.', 3),
];

$isDocumentedPlaceholder = static function (string $match) use ($documentedPlaceholders): bool {
    foreach ($documentedPlaceholders as $placeholder) {
        if ($match === $placeholder) {
            return true;
        }
    }

    // An example key is allowed only when it is obviously not entropy: a
    // single repeated character, or an ellipsis.
    if (preg_match('#^base64:(\.{3}|([A-Za-z0-9+/])\2{20,}={0,2})$#', $match)) {
        return true;
    }

    return false;
};

for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);

    if (!preg_match('#\.(php|md|json|ya?ml|blade\.php|stub|env|txt)$#', $name)) {
        continue;
    }

    $contents = (string) $zip->getFromIndex($i);

    foreach ($secretPatterns as $label => $pattern) {
        if (!preg_match_all($pattern, $contents, $matches)) {
            continue;
        }

        foreach ($matches[0] as $match) {
            // Only the APP_KEY pattern has a legitimate illustrative form, and
            // only in documentation: a weak-looking key in config or source is
            // still a leak. AWS keys, bearer tokens and private key blocks are
            // never exempt, in any file.
            $mayIllustrate = str_starts_with($name, 'docs/')
                || in_array($name, ['UPGRADE.md', 'README.md'], true);

            if ($label === 'APP_KEY value' && $mayIllustrate && $isDocumentedPlaceholder($match)) {
                continue;
            }

            $failures[] = "Possible {$label} in shipped file {$name}";
            break;
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
    'config' => ['allow-plugins' => false],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

out('Installing the artifact as a dependency…');

// A real install, not --dry-run: only this exercises autoloading and Laravel
// package discovery against the shipped files. A dry run resolves versions and
// proves nothing about whether the archive actually works.
exec(
    $composer . ' install --no-interaction --no-progress --prefer-dist'
    . ' --working-dir=' . escapeshellarg($installDir) . ' 2>&1',
    $installOutput,
    $installCode
);

$installLog = implode(PHP_EOL, $installOutput);
$offline = str_contains($installLog, 'curl error')
    || str_contains($installLog, 'could not be fully loaded')
    || str_contains($installLog, 'network is disabled');

if ($installCode !== 0) {
    if ($offline && $allowOffline) {
        $notes[] = 'Install check NOT RUN: package metadata is unreachable and --allow-offline was passed.';
        $notes[] = 'This run does NOT qualify the artifact for release.';
    } elseif ($offline) {
        $failures[] = 'The artifact could not be installed because package metadata is unreachable. '
            . 'Installability is a release gate; re-run with network access, or pass --allow-offline '
            . 'to acknowledge an incomplete run.';
    } else {
        $failures[] = 'The artifact could not be installed as a dependency.';
        $notes[] = trim($installLog);
    }
} else {
    $installedManifest = $installDir . '/vendor/livenetworks/ln-starter/composer.json';

    if (!file_exists($installedManifest)) {
        $failures[] = 'Install succeeded but the package is not present in vendor/.';
    } else {
        // Autoload and boot-level sanity: the shipped classes must be loadable
        // from the installed artifact, not merely present in the archive.
        $probe = <<<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
$classes = [
    LiveNetworks\LnStarter\LnStarterServiceProvider::class,
    LiveNetworks\LnStarter\Http\Controllers\AuthController::class,
    LiveNetworks\LnStarter\Security\SecurityEventDispatcher::class,
    LiveNetworks\LnStarter\Security\ContextSanitizer::class,
    LiveNetworks\LnStarter\Contracts\SecurityAuditSink::class,
];
foreach ($classes as $class) {
    if (!class_exists($class) && !interface_exists($class)) {
        fwrite(STDERR, "missing: {$class}
");
        exit(1);
    }
}
$config = require __DIR__ . '/vendor/livenetworks/ln-starter/config/ln-starter.php';
if (!is_array($config) || !isset($config['auth'], $config['logging'])) {
    fwrite(STDERR, "config did not load
");
    exit(1);
}
echo "autoload ok
";
PHP;

        file_put_contents($installDir . '/probe.php', $probe);

        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($installDir . '/probe.php') . ' 2>&1',
            $probeOutput,
            $probeCode
        );

        if ($probeCode !== 0) {
            $failures[] = 'The installed artifact does not autoload: ' . implode(' ', $probeOutput);
        } else {
            $notes[] = 'Install check PASSED: the artifact installs and autoloads from the archive.';

            // Autoloading is not package discovery. A minimal Composer project
            // has no artisan and no post-autoload-dump hook, so the provider is
            // never booted as a Laravel package here. That is proven by the
            // consumer harness, which this script hands the extracted archive.
            $notes[] = 'Package discovery is NOT exercised here; run:';
            $notes[] = '  php scripts/consumer-install.php --laravel=13 --package-path=' . $packageRoot;
        }
    }
}

// ---------------------------------------------------------------- cleanup
if ($keepExtracted) {
    foreach ($notes as $note) {
        out('  note: ' . $note);
    }

    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, '  FAIL: ' . $failure . PHP_EOL);
        }

        fwrite(STDERR, PHP_EOL . count($failures) . ' artifact check(s) failed.' . PHP_EOL);
        exit(1);
    }

    out('');
    out('Extracted artifact kept for the consumer harness:');
    out('  ' . $packageRoot);

    // Written only after every check has passed, so a consumer job can
    // never be handed the path of an artifact that failed inspection.
    if ($outputPathFile !== null && $outputPathFile !== '') {
        $written = file_put_contents($outputPathFile, $packageRoot . PHP_EOL, LOCK_EX);

        if ($written === false) {
            fwrite(STDERR, 'Unable to write the artifact path to ' . $outputPathFile . PHP_EOL);
            exit(1);
        }

        out('  path written to ' . $outputPathFile);
    }

    exit(0);
}

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

if ($allowOffline) {
    out('Artifact content checks passed — PARTIAL RUN, installability not proven.');
    exit(0);
}

out('Artifact verification passed, including installation from the archive.');
exit(0);
