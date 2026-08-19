<?php

/**
 * Release preflight.
 *
 * Fail-closed: every check must pass for exit code 0, and anything that cannot
 * be proven is a failure rather than a warning. The archive work is delegated
 * to verify-artifact.php rather than reimplemented, so the artifact this script
 * checksums is byte-for-byte the one that passed inspection — a rebuild would
 * be a different artifact (ADR 0004).
 *
 * Usage:
 *   php scripts/release-check.php --version=v2.0.0
 *   php scripts/release-check.php --version=v2.0.0 --allow-dirty --allow-offline
 *
 * The two --allow flags exist for local iteration. Either one marks the run as
 * NOT qualifying for release, and that is stated in the output and in the exit
 * summary; it never turns a failure into a pass.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$options = getopt('', ['version:', 'allow-dirty', 'allow-offline', 'keep', 'output-dir:']);

$version = $options['version'] ?? null;
$allowDirty = array_key_exists('allow-dirty', $options);
$allowOffline = array_key_exists('allow-offline', $options);
$keep = array_key_exists('keep', $options);

// Where the qualified archive and its checksum are copied for a caller to
// publish. This is the ONLY way to obtain a release artifact: a second
// `composer archive` produces a different artifact, however identical it
// looks, and would not be the file these checks passed on (ADR 0004).
$outputDir = $options['output-dir'] ?? null;

$failures = [];
$notes = [];
$disqualifiers = [];

function out(string $line = ''): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

function step(string $label): void
{
    fwrite(STDOUT, str_pad('  ' . $label, 58, '.'));
}

function verdict(bool $ok): void
{
    fwrite(STDOUT, ($ok ? ' ok' : ' FAIL') . PHP_EOL);
}

function run(string $command, ?string $cwd = null): array
{
    $full = $cwd !== null ? 'cd ' . escapeshellarg($cwd) . ' && ' . $command : $command;
    exec($full . ' 2>&1', $output, $code);

    return ['code' => $code, 'output' => implode(PHP_EOL, $output)];
}

// ------------------------------------------------------------------ version
out('LN-Starter release preflight');
out();

if ($version === null) {
    fwrite(STDERR, 'Missing --version. Example: --version=v2.0.0' . PHP_EOL);
    exit(1);
}

step('Version is canonical SemVer');

// The v prefix is the canonical form per ADR 0004. Pre-release and build
// metadata are refused outright: this package has no channel that publishes
// them, and accepting them here would imply one exists.
$validVersion = (bool) preg_match('/^v(\d+)\.(\d+)\.(\d+)$/', $version, $semver);
verdict($validVersion);

if (!$validVersion) {
    $failures[] = sprintf(
        'Version %s is not vMAJOR.MINOR.PATCH. Pre-release and build metadata are not published by this package.',
        $version
    );

    // Everything below is keyed off the version, so there is nothing useful
    // left to check.
    out();
    fwrite(STDERR, 'FAIL: ' . $failures[0] . PHP_EOL);
    exit(1);
}

$plain = substr($version, 1);

// ------------------------------------------------------------------ git state
step('Working tree is clean');
$status = run('git -C ' . escapeshellarg($root) . ' status --porcelain');
$clean = $status['code'] === 0 && trim($status['output']) === '';
verdict($clean || $allowDirty);

if (!$clean) {
    if ($allowDirty) {
        $disqualifiers[] = 'The working tree was dirty and --allow-dirty was passed.';
        $notes[] = 'Uncommitted changes:' . PHP_EOL . $status['output'];
    } else {
        $failures[] = 'The working tree is not clean. A release is built from committed state only.' . PHP_EOL . $status['output'];
    }
}

step('Tag, if it exists, points at HEAD');
$head = trim(run('git -C ' . escapeshellarg($root) . ' rev-parse HEAD')['output']);
$tagged = run('git -C ' . escapeshellarg($root) . ' rev-list -n 1 ' . escapeshellarg($version));
$tagExists = $tagged['code'] === 0;
$tagAgrees = !$tagExists || trim($tagged['output']) === $head;
verdict($tagAgrees);

if (!$tagAgrees) {
    $failures[] = sprintf(
        'Tag %s points at %s but HEAD is %s. The tag must be on the commit that was qualified.',
        $version,
        substr(trim($tagged['output']), 0, 12),
        substr($head, 0, 12)
    );
}

// ADR 0004 requires an annotated tag. A lightweight tag carries no tagger, no
// date and no message, so it records nothing about who released what.
if ($tagExists) {
    step('Tag is annotated');
    $type = run('git -C ' . escapeshellarg($root) . ' cat-file -t ' . escapeshellarg($version));
    $annotated = trim($type['output']) === 'tag';
    verdict($annotated);

    if (!$annotated) {
        $failures[] = sprintf(
            'Tag %s is %s, not an annotated tag. Create it with `git tag -a`.',
            $version,
            trim($type['output']) ?: 'unreadable'
        );
    }
}

$notes[] = $tagExists
    ? sprintf('Tag %s exists and points at HEAD.', $version)
    : sprintf('Tag %s does not exist yet; this is a candidate.', $version);

// ------------------------------------------------------------------ documents
step('Release notes exist');
$notesPath = $root . '/docs/releases/' . $plain . '.md';
$notesOk = is_file($notesPath) && strlen((string) file_get_contents($notesPath)) > 500;
verdict($notesOk);

if (!$notesOk) {
    $failures[] = sprintf(
        'docs/releases/%s.md is missing or too short to be real release notes.',
        $plain
    );
}

// A tag means this is no longer a candidate. Release notes that still say so
// would be published verbatim on the GitHub Release.
if ($tagExists && $notesOk) {
    step('Release notes are finalised');
    $notesBody = strtolower((string) file_get_contents($notesPath));
    $stillCandidate = str_contains($notesBody, 'release candidate')
        || str_contains($notesBody, 'not tagged')
        || str_contains($notesBody, 'not published');
    verdict(!$stillCandidate);

    if ($stillCandidate) {
        $failures[] = sprintf(
            'docs/releases/%s.md still describes itself as a release candidate while tag %s exists. '
            . 'Finalise the notes in their own commit, re-qualify, then tag.',
            $plain,
            $version
        );
    }
}

step('Changelog has a section for this version');
$changelog = (string) file_get_contents($root . '/CHANGELOG.md');
$changelogOk = str_contains($changelog, '## [' . $plain . ']');
verdict($changelogOk);

if (!$changelogOk) {
    $failures[] = sprintf('CHANGELOG.md has no "## [%s]" section.', $plain);
}

// A tagged release may not still describe itself as unreleased.
if ($tagExists && $changelogOk) {
    step('Changelog section is not marked unreleased');
    $sectionLine = '';

    foreach (explode("\n", $changelog) as $line) {
        if (str_starts_with($line, '## [' . $plain . ']')) {
            $sectionLine = $line;
            break;
        }
    }

    $dated = !str_contains(strtolower($sectionLine), 'unreleased');
    verdict($dated);

    if (!$dated) {
        $failures[] = sprintf(
            'CHANGELOG.md still marks %s as unreleased while the tag exists: %s',
            $plain,
            trim($sectionLine)
        );
    }
}

// ------------------------------------------------------------------ manifest
step('Composer manifest is valid');
$validate = run('composer validate --strict --no-interaction', $root);
$manifestOk = $validate['code'] === 0;
verdict($manifestOk);

if (!$manifestOk) {
    $failures[] = 'composer validate --strict failed:' . PHP_EOL . $validate['output'];
}

// ------------------------------------------------------------------ hygiene
step('Tracked text is control-byte free and UTF-8');
$listing = run('git -C ' . escapeshellarg($root) . ' ls-files');
$offences = [];

if ($listing['code'] !== 0) {
    $offences[] = 'git ls-files failed';
} else {
    foreach (explode("\n", trim(str_replace("\r", '', $listing['output']))) as $relative) {
        $relative = trim($relative);
        $path = $root . '/' . $relative;

        if ($relative === '' || !is_file($path)) {
            continue;
        }

        if (!preg_match('/\.(php|ya?ml|json|md|stub|xml|scss|js|txt)$/i', $relative)) {
            continue;
        }

        $contents = (string) file_get_contents($path);

        // C0 controls except tab, LF and CR. These survive php -l and
        // git diff --check, and only show up as a broken regex or a
        // workflow the YAML parser rejects.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $contents)) {
            $offences[] = $relative . ' contains a control character';
        }

        if (!mb_check_encoding($contents, 'UTF-8')) {
            $offences[] = $relative . ' is not valid UTF-8';
        }
    }
}

verdict($offences === []);

if ($offences !== []) {
    $failures[] = 'Source hygiene failed:' . PHP_EOL . '  ' . implode(PHP_EOL . '  ', $offences);
}

// ------------------------------------------------------------------ artifact
// Building an archive costs a full Composer install, and no outcome of it can
// rescue a run that has already failed. Skipping it keeps a preflight that is
// going to fail fast, and keeps the reason at the top of the output.
if ($failures !== []) {
    out();
    out('Artifact NOT inspected: ' . count($failures) . ' earlier check(s) already failed.');
    out();

    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }

    fwrite(STDERR, PHP_EOL . count($failures) . ' release check(s) failed.' . PHP_EOL);
    exit(1);
}

out();
out('Building and inspecting the artifact (delegated to verify-artifact.php)');
out();

$workdir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ln-release-' . bin2hex(random_bytes(6));

if (!mkdir($workdir, 0700, true) && !is_dir($workdir)) {
    fwrite(STDERR, 'Unable to create ' . $workdir . PHP_EOL);
    exit(1);
}

$pathFile = $workdir . DIRECTORY_SEPARATOR . 'artifact-path.txt';
$archiveFile = $workdir . DIRECTORY_SEPARATOR . 'archive-path.txt';

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/verify-artifact.php')
    . ' --keep-extracted'
    . ' --output-path-file=' . escapeshellarg($pathFile)
    . ' --output-archive-file=' . escapeshellarg($archiveFile)
    . ($allowOffline ? ' --allow-offline' : '');

$artifact = run($command);
out($artifact['output']);

$artifactOk = $artifact['code'] === 0;

if (!$artifactOk) {
    $failures[] = 'verify-artifact.php failed; see its output above.';
}

$archivePath = null;
$extractedPath = null;
$checksum = null;

if ($artifactOk) {
    $extractedPath = is_file($pathFile) ? trim((string) file_get_contents($pathFile)) : '';
    $archivePath = is_file($archiveFile) ? trim((string) file_get_contents($archiveFile)) : '';

    step('Artifact handoff files were written');
    $handoffOk = $extractedPath !== '' && is_dir($extractedPath)
        && $archivePath !== '' && is_file($archivePath);
    verdict($handoffOk);

    if (!$handoffOk) {
        $failures[] = 'verify-artifact.php passed but did not hand back a usable archive and extracted directory.';
    } else {
        step('Checksum generated');
        $checksum = hash_file('sha256', $archivePath);
        verdict($checksum !== false);

        if ($checksum === false) {
            $failures[] = 'Unable to compute a SHA-256 for ' . $archivePath;
        } else {
            $checksumPath = $archivePath . '.sha256';
            $line = $checksum . '  ' . basename($archivePath) . PHP_EOL;

            if (file_put_contents($checksumPath, $line, LOCK_EX) === false) {
                $failures[] = 'Unable to write ' . $checksumPath;
            } else {
                $notes[] = 'Checksum file: ' . $checksumPath;
            }
        }

        // The point of ADR 0004's "one artifact" rule: the file that was
        // inspected, the file that gets checksummed and the file a consumer
        // job installs from must all be this one. Asserted rather than
        // assumed, because a second `composer archive` looks identical.
        step('Extracted directory came from this archive');
        $sameBuild = str_starts_with($extractedPath, dirname($archivePath));
        verdict($sameBuild);

        if (!$sameBuild) {
            $failures[] = sprintf(
                'The extracted package (%s) is not from the archive that was checksummed (%s).',
                $extractedPath,
                $archivePath
            );
        }
    }
}

if ($allowOffline) {
    $disqualifiers[] = '--allow-offline was passed, so installability was not proven.';
}

// ------------------------------------------------------------------ report
out();
out(str_repeat('-', 62));

foreach ($notes as $note) {
    out('note: ' . $note);
}

if ($archivePath !== null && $archivePath !== '' && is_file($archivePath)) {
    out();
    out('Artifact:  ' . basename($archivePath));
    out('Size:      ' . number_format(filesize($archivePath)) . ' bytes');
    out('SHA-256:   ' . ($checksum ?: 'not computed'));
    out('Extracted: ' . $extractedPath);
}

out();

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }

    fwrite(STDERR, PHP_EOL . count($failures) . ' release check(s) failed.' . PHP_EOL);
    exit(1);
}

if ($disqualifiers !== []) {
    out('Checks passed, but this run does NOT qualify ' . $version . ' for release:');

    foreach ($disqualifiers as $reason) {
        out('  - ' . $reason);
    }

    out();
    out('Re-run without the --allow flags on a clean tree with network access.');
    exit(1);
}

// The qualified artifact is exported here, before the success message, so a
// failure to export is a failure of the run. A caller that publishes must take
// these bytes: rebuilding would publish something these checks never saw.
if ($outputDir !== null && $outputDir !== '') {
    if (!is_dir($outputDir) && !mkdir($outputDir, 0700, true) && !is_dir($outputDir)) {
        fwrite(STDERR, 'Unable to create output directory ' . $outputDir . PHP_EOL);
        exit(1);
    }

    $exports = [
        $archivePath => $outputDir . DIRECTORY_SEPARATOR . basename((string) $archivePath),
        $archivePath . '.sha256' => $outputDir . DIRECTORY_SEPARATOR . basename((string) $archivePath) . '.sha256',
    ];

    foreach ($exports as $from => $to) {
        if (!is_file($from) || !copy($from, $to)) {
            fwrite(STDERR, 'Unable to export ' . $from . ' to ' . $to . PHP_EOL);
            exit(1);
        }
    }

    // Re-checksum what actually landed. A truncated copy would otherwise be
    // published under the checksum of the file it was copied from.
    $exported = $outputDir . DIRECTORY_SEPARATOR . basename((string) $archivePath);

    if (hash_file('sha256', $exported) !== $checksum) {
        fwrite(STDERR, 'Exported archive does not match the checksum it was qualified under.' . PHP_EOL);
        exit(1);
    }

    out();
    out('Exported the qualified artifact to ' . $outputDir);
    out('  ' . basename((string) $archivePath));
    out('  ' . basename((string) $archivePath) . '.sha256');
}

out();
out('Release preflight passed for ' . $version . '.');
out('This qualifies the artifact above. Publishing it still requires a tag and');
out('a green release workflow run — see ADR 0004.');

if (!$keep && $archivePath !== null && $archivePath !== '') {
    out();
    out('Artifact retained at ' . dirname($archivePath));
}

exit(0);
