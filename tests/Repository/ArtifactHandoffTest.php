<?php

namespace LiveNetworks\LnStarter\Tests\Repository;

use PHPUnit\Framework\TestCase;

/**
 * The artifact-to-consumer handoff.
 *
 * `--output-path-file` was once declared and never written, so the CI step that
 * reads it could only ever fail. Nothing in the PHP suite noticed, because the
 * variable was syntactically fine. These tests exercise the contract itself.
 */
class ArtifactHandoffTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $this->script = dirname(__DIR__, 2) . '/scripts/verify-artifact.php';

        if (!is_file($this->script)) {
            $this->markTestSkipped('verify-artifact.php is missing.');
        }
    }

    private function requireZip(): void
    {
        // The *child* process builds the archive, so its interpreter is what
        // needs the extension. Checking the parent would skip on CI, where zip
        // is present, and fatal where only the parent has it via -d.
        //
        // Probed through a temp file rather than php -r: on Windows
        // escapeshellarg() replaces double quotes with spaces, which silently
        // corrupts inline code.
        //
        // tempnam() creates the file itself, so its return value is used as
        // is. Appending an extension would orphan the created file and delete
        // only the second path; the CLI does not need a .php suffix.
        $probe = tempnam(sys_get_temp_dir(), 'ln-zip-probe');

        if ($probe === false) {
            $this->markTestSkipped('No writable temporary directory for the zip probe.');
        }

        try {
            file_put_contents($probe, '<?php exit(extension_loaded("zip") ? 0 : 1);');

            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1', $ignored, $code);
        } finally {
            @unlink($probe);
        }

        if ($code !== 0) {
            $this->markTestSkipped('The zip extension is unavailable to ' . PHP_BINARY . '.');
        }
    }

    /** @return array{code:int, output:string} */
    private function runScript(array $arguments): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->script);

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        exec($command . ' 2>&1', $lines, $code);

        return ['code' => $code, 'output' => implode("\n", $lines)];
    }

    /**
     * Static guarantee, independent of whether the script can run here: the
     * option must actually be consumed, not merely accepted.
     */
    public function test_the_option_is_written_not_only_read(): void
    {
        $source = (string) file_get_contents($this->script);

        $this->assertStringContainsString(
            '$outputPathFile = $options[',
            $source,
            'the option is not read at all'
        );

        $this->assertMatchesRegularExpression(
            '/file_put_contents\(\s*\$outputPathFile/',
            $source,
            'the option is read but never written, so the CI handoff cannot work'
        );

        $this->assertStringContainsString(
            '--output-path-file requires --keep-extracted',
            $source,
            'a path may not be handed back for a directory that is about to be deleted'
        );
    }

    /**
     * The installability probe must model a Laravel application, not the
     * package's bare constraint set.
     *
     * The shipped config calls env(). illuminate/support provides that helper
     * but does not require vlucas/phpdotenv (laravel/framework does), so a
     * probe built from this package's own requirements alone fatals on a
     * missing PhpOption before it can read a single config key. The local
     * suite never saw it, because testbench drags in the whole framework.
     *
     * The real gate is the artifact job, which performs the install for
     * real. This is the cheap source-level guard against silently dropping
     * the baseline again.
     */
    public function test_the_install_probe_models_a_laravel_baseline(): void
    {
        $source = (string) file_get_contents($this->script);

        $this->assertMatchesRegularExpression(
            '/vlucas\/phpdotenv/',
            $source,
            'the probe cannot evaluate a config that calls env() without phpdotenv'
        );
    }

    public function test_the_option_is_refused_without_keep_extracted(): void
    {
        $this->requireZip();

        $result = $this->runScript(['--output-path-file=' . sys_get_temp_dir() . '/ln-handoff-probe.txt']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('requires --keep-extracted', $result['output']);
    }

    /**
     * End-to-end: run the real script and assert the file it promises CI.
     */
    public function test_the_path_file_names_the_extracted_artifact(): void
    {
        $this->requireZip();

        $target = sys_get_temp_dir() . '/ln-handoff-' . bin2hex(random_bytes(6)) . '.txt';

        $result = $this->runScript([
            '--keep-extracted',
            '--output-path-file=' . $target,
            // The machine running the suite may have no network; the handoff
            // contract is what is under test, not installability.
            '--allow-offline',
        ]);

        try {
            // A skip is only honest for a precondition this machine cannot
            // satisfy. Composer is the one such tool the script needs and
            // cannot pre-check (zip is checked above). Every other non-zero
            // exit is a real failure of the handoff contract and must be red,
            // with the whole output, rather than disappearing into a skip.
            if ($result['code'] !== 0) {
                if (str_contains($result['output'], 'Composer not found')) {
                    $this->markTestSkipped('Composer is unavailable; the archive cannot be built here.');
                }

                $this->fail(
                    'verify-artifact.php exited with ' . $result['code']
                    . ' but the handoff contract must hold:' . PHP_EOL . $result['output']
                );
            }

            $this->assertFileExists($target, 'the path file was never written');

            $path = trim((string) file_get_contents($target));

            $this->assertNotSame('', $path, 'the path file is empty');
            $this->assertDirectoryExists($path, 'the recorded path is not an extracted artifact');
            $this->assertFileExists($path . '/composer.json', 'the recorded directory is not a package root');

            // The consumer harness passes this straight to realpath(), so a
            // stray control character or newline artefact would break it.
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $path);
            $this->assertSame($path, trim($path));
        } finally {
            if (is_file($target)) {
                $recorded = trim((string) file_get_contents($target));
                @unlink($target);
                $this->removeExtractedWorkspace($recorded);
            }
        }
    }

    /**
     * Scoped cleanup: only a directory this script's own workspace naming
     * scheme could have produced.
     */
    private function removeExtractedWorkspace(string $extracted): void
    {
        if ($extracted === '' || !is_dir($extracted)) {
            return;
        }

        $workspace = dirname($extracted);

        // Matched on the basename, not the whole path: inside a character
        // class PCRE reads \/ as an escaped forward slash, so [\/] never
        // matches a Windows separator and the cleanup silently never ran.
        if (!preg_match('/^ln-starter-artifact-[0-9a-f]{12}$/', basename($workspace))) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($workspace);
    }
}
