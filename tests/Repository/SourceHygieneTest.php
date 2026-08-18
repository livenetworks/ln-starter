<?php

namespace LiveNetworks\LnStarter\Tests\Repository;

use PHPUnit\Framework\TestCase;

/**
 * Byte-level hygiene of tracked source.
 *
 * A stray control character survives every check that reads a file as text:
 * `php -l` accepts it inside a string literal, PHPUnit is happy, and
 * `git diff --check` says nothing. It only shows up as a broken regex
 * backreference or a workflow the YAML parser rejects. So it is checked
 * directly, in bytes.
 */
class SourceHygieneTest extends TestCase
{
    /**
     * C0 controls except tab (0x09), LF (0x0A) and CR (0x0D).
     */
    private const FORBIDDEN = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

    /** @return list<string> */
    private function trackedTextFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $listing = shell_exec('git -C ' . escapeshellarg($root) . ' ls-files');

        if (!is_string($listing) || trim($listing) === '') {
            $this->markTestSkipped('git file listing is unavailable.');
        }

        $files = [];

        foreach (explode("\n", trim($listing)) as $relative) {
            $relative = trim($relative);
            $path = $root . '/' . $relative;

            if ($relative === '' || !is_file($path)) {
                continue;
            }

            if (!preg_match('/\.(php|ya?ml|json|md|blade\.php|stub|xml|scss|js|txt|gitignore)$/i', $relative)
                && !in_array(basename($relative), ['.gitignore', '.gitmodules', 'LICENSE'], true)) {
                continue;
            }

            $files[] = $relative;
        }

        return $files;
    }

    public function test_no_tracked_text_file_contains_a_control_character(): void
    {
        $root = dirname(__DIR__, 2);
        $offences = [];

        foreach ($this->trackedTextFiles() as $relative) {
            $contents = (string) file_get_contents($root . '/' . $relative);

            if (!preg_match_all(self::FORBIDDEN, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$char, $offset]) {
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $offences[] = sprintf('%s:%d contains byte 0x%02X', $relative, $line, ord($char));
            }
        }

        $this->assertSame(
            [],
            $offences,
            "Control characters in tracked source.\n"
            . "This is almost always an escape sequence that was written literally —\n"
            . "for example a regex backreference or a sed replacement produced by a\n"
            . "generator that interpreted \1 as an octal escape.\n\n"
            . implode("\n", $offences)
        );
    }

    public function test_every_tracked_text_file_is_valid_utf8(): void
    {
        $root = dirname(__DIR__, 2);
        $offences = [];

        foreach ($this->trackedTextFiles() as $relative) {
            $contents = (string) file_get_contents($root . '/' . $relative);

            if (!mb_check_encoding($contents, 'UTF-8')) {
                $offences[] = $relative;
            }
        }

        $this->assertSame([], $offences, 'Tracked files are not valid UTF-8: ' . implode(', ', $offences));
    }

    /**
     * The workflow drives every release gate, so a parse failure there means
     * none of them run. Parsed rather than eyeballed.
     */
    public function test_the_workflow_parses_and_declares_the_expected_jobs(): void
    {
        $path = dirname(__DIR__, 2) . '/.github/workflows/tests.yml';

        if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            $this->markTestSkipped('symfony/yaml is unavailable.');
        }

        $parsed = \Symfony\Component\Yaml\Yaml::parseFile($path);

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('jobs', $parsed);

        foreach (['test', 'artifact', 'consumer'] as $job) {
            $this->assertArrayHasKey($job, $parsed['jobs'], "workflow job {$job} is missing");
        }

        // 3 frameworks x 3 PHP x 3 databases, minus the excluded EOL x 8.5
        // combination across all three databases.
        $matrix = $parsed['jobs']['test']['strategy']['matrix'];
        $lanes = count($matrix['framework']) * count($matrix['php']) * count($matrix['database'])
            - count($matrix['exclude']) * count($matrix['database']);

        $this->assertSame(24, $lanes, 'unexpected number of test lanes');
    }
}
