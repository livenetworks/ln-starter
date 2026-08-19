<?php

namespace LiveNetworks\LnStarter\Tests\Repository;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The release pipeline is the one workflow whose failure mode is publishing
 * something, so its structure is asserted rather than reviewed.
 *
 * These are deliberately structural: a release cannot be rehearsed from the
 * suite, and the parts that matter — what triggers it, what it is permitted to
 * do, whether an action can be swapped under a moving tag, whether any gate can
 * be softened — are all visible in the file itself.
 */
class ReleaseWorkflowTest extends TestCase
{
    private const RELEASE = '/.github/workflows/release.yml';
    private const TESTS = '/.github/workflows/tests.yml';

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function releaseYaml(): array
    {
        if (!class_exists(Yaml::class)) {
            $this->markTestSkipped('symfony/yaml is unavailable.');
        }

        $parsed = Yaml::parseFile($this->root() . self::RELEASE);

        $this->assertIsArray($parsed);

        return $parsed;
    }

    /** @return array{code:int, output:string} */
    private function releaseCheck(array $arguments): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root() . '/scripts/release-check.php');

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        exec($command . ' 2>&1', $lines, $code);

        return ['code' => $code, 'output' => implode("\n", $lines)];
    }

    public function test_the_release_workflow_parses_and_declares_its_gates(): void
    {
        $parsed = $this->releaseYaml();

        $this->assertArrayHasKey('jobs', $parsed);

        foreach (['qualify', 'artifact', 'consumer', 'publish'] as $job) {
            $this->assertArrayHasKey($job, $parsed['jobs'], "release job {$job} is missing");
        }
    }

    /**
     * A tag, or an explicit manual rehearsal. Never a branch push — that would
     * make every commit a release candidate.
     */
    public function test_it_triggers_only_on_a_version_tag_or_a_manual_run(): void
    {
        $parsed = $this->releaseYaml();

        // YAML 1.1 parses the bare key `on` as boolean true.
        $triggers = $parsed['on'] ?? $parsed[true] ?? null;

        $this->assertIsArray($triggers, 'the workflow declares no triggers');
        $this->assertSame(['push', 'workflow_dispatch'], array_keys($triggers));
        $this->assertSame(['v*'], $triggers['push']['tags']);
        $this->assertArrayNotHasKey('branches', $triggers['push']);
        $this->assertArrayNotHasKey('pull_request', $triggers);
    }

    public function test_permissions_are_least_privilege(): void
    {
        $parsed = $this->releaseYaml();

        $this->assertSame(['contents' => 'read'], $parsed['permissions'], 'the workflow default must be read-only');

        foreach (['qualify', 'artifact', 'consumer'] as $job) {
            $this->assertArrayNotHasKey(
                'permissions',
                $parsed['jobs'][$job],
                "job {$job} must inherit the read-only default"
            );
        }

        $this->assertSame(
            ['contents' => 'write'],
            $parsed['jobs']['publish']['permissions'],
            'only the publish job may write, and only contents'
        );
    }

    /**
     * A tag is mutable. An action pinned to `@v4` can become different code
     * between the run that was reviewed and the run that publishes.
     */
    public function test_every_action_is_pinned_to_an_immutable_commit(): void
    {
        foreach ([self::RELEASE, self::TESTS] as $workflow) {
            $contents = (string) file_get_contents($this->root() . $workflow);

            preg_match_all('/uses:\s*(\S+)/', $contents, $matches);

            $this->assertNotEmpty($matches[1], "no actions found in {$workflow}");

            foreach ($matches[1] as $use) {
                // A local reusable workflow is a path in this repository, not a
                // third-party action: it moves with the commit under review and
                // has no SHA to pin.
                if (str_starts_with($use, './')) {
                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/@[0-9a-f]{40}$/',
                    $use,
                    "{$workflow} uses {$use}, which is not pinned to a full commit SHA"
                );
            }
        }
    }

    /**
     * continue-on-error turns a red gate green while leaving the log looking
     * as though it ran.
     */
    public function test_no_gate_can_be_softened(): void
    {
        foreach ([self::RELEASE, self::TESTS] as $workflow) {
            $contents = (string) file_get_contents($this->root() . $workflow);

            $this->assertStringNotContainsString(
                'continue-on-error',
                $contents,
                "{$workflow} contains continue-on-error"
            );
        }
    }

    public function test_publishing_requires_a_tag_and_every_gate(): void
    {
        $parsed = $this->releaseYaml();
        $publish = $parsed['jobs']['publish'];

        $this->assertSame(['qualify', 'artifact', 'consumer'], $publish['needs']);

        $this->assertStringContainsString(
            "startsWith(github.ref, 'refs/tags/v')",
            $publish['if'],
            'the publish job must be gated on a version tag'
        );

        $this->assertStringContainsString(
            "github.event_name == 'push'",
            $publish['if'],
            'a manual dry run must not publish'
        );
    }

    /**
     * The archive is built once and every later job consumes that build. A
     * rebuild would be a different artifact (ADR 0004), so the consumer and
     * publish jobs must download rather than rebuild.
     */
    public function test_downstream_jobs_use_the_uploaded_archive(): void
    {
        $parsed = $this->releaseYaml();

        foreach (['consumer', 'publish'] as $job) {
            $steps = $parsed['jobs'][$job]['steps'];
            $uses = array_column($steps, 'uses');

            $this->assertNotEmpty(
                array_filter($uses, static fn ($u) => is_string($u) && str_contains($u, 'download-artifact')),
                "job {$job} must download the qualified archive, not rebuild it"
            );

            $runs = implode("\n", array_filter(array_column($steps, 'run')));

            $this->assertStringContainsString(
                'sha256sum --check',
                $runs,
                "job {$job} must verify the checksum before using the archive"
            );
        }
    }

    /**
     * The qualification a release runs must BE the branch qualification, not a
     * reduced copy of it. A release matrix that dropped a lane could publish on
     * weaker evidence than the branch it came from.
     */
    public function test_qualification_reuses_the_full_test_workflow(): void
    {
        $parsed = $this->releaseYaml();
        $qualify = $parsed['jobs']['qualify'];

        $this->assertSame(
            './.github/workflows/tests.yml',
            $qualify['uses'] ?? null,
            'the release must call the full Tests workflow, not redefine a matrix'
        );

        $this->assertArrayNotHasKey(
            'strategy',
            $qualify,
            'a release-local matrix would be a second, weaker definition of qualification'
        );

        $tests = Yaml::parseFile($this->root() . self::TESTS);
        $triggers = $tests['on'] ?? $tests[true] ?? [];

        $this->assertArrayHasKey(
            'workflow_call',
            $triggers,
            'tests.yml must be callable for the release to reuse it'
        );
    }

    /**
     * ADR 0004's canonical-artifact rule. The preflight builds, inspects,
     * checksums and exports one archive; anything that ran `composer archive`
     * again would upload a different artifact from the one that was qualified.
     */
    public function test_the_release_builds_exactly_one_archive(): void
    {
        $contents = (string) file_get_contents($this->root() . self::RELEASE);

        $this->assertStringContainsString('--output-dir=', $contents, 'the preflight must export the qualified archive');

        $this->assertStringNotContainsString(
            'verify-artifact.php',
            $contents,
            'the release workflow must not build a second archive; release-check.php exports the qualified one'
        );

        $this->assertSame(
            1,
            substr_count($contents, 'php scripts/release-check.php'),
            'the preflight must be invoked exactly once'
        );

        $preflight = (string) file_get_contents($this->root() . '/scripts/release-check.php');

        $this->assertStringContainsString(
            'hash_file(' . chr(39) . 'sha256' . chr(39) . ', $exported) !== $checksum',
            $preflight,
            'the exported bytes must be re-checksummed against the qualified value'
        );
    }

    /**
     * A tag name is attacker-influenced text. Git permits `v1.0.0$(id)`, and
     * ${{ }} pastes it into the shell before anything can validate it.
     */
    public function test_untrusted_values_never_reach_the_shell_through_interpolation(): void
    {
        $parsed = $this->releaseYaml();

        foreach ($parsed['jobs'] as $name => $job) {
            foreach ($job['steps'] ?? [] as $step) {
                $script = $step['run'] ?? null;

                if (!is_string($script)) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression(
                    '/\$\{\{\s*(inputs|steps|github\.ref|github\.event)/',
                    $script,
                    sprintf('job %s interpolates an untrusted value into a run block; pass it through env instead', $name)
                );
            }
        }
    }

    public function test_the_version_is_validated_before_it_is_used(): void
    {
        $contents = (string) file_get_contents($this->root() . self::RELEASE);

        // Both the step that publishes the value and the step that publishes
        // the release check the shape themselves.
        $this->assertSame(
            2,
            substr_count($contents, "grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$'"),
            'the version must be validated where it is resolved and again where it is published'
        );
    }

    /**
     * The upgrade path is the one a real 1.x consumer takes, so it must be
     * proven against the bytes about to be published, not only the working
     * tree.
     */
    public function test_the_upgrade_harness_runs_against_the_release_archive(): void
    {
        $parsed = $this->releaseYaml();
        $runs = implode("
", array_filter(array_column($parsed['jobs']['consumer']['steps'], 'run')));

        foreach (['consumer-install.php', 'consumer-upgrade.php'] as $script) {
            $this->assertStringContainsString(
                $script,
                $runs,
                sprintf('the release consumer job must run %s from the archive', $script)
            );
        }

        $this->assertStringContainsString('release/extracted', $runs, 'both harnesses must use the extracted archive');
    }

    /**
     * A disqualified run must not leave a publishable artifact behind. The
     * export happens after the disqualifier check, so --allow-dirty or
     * --allow-offline produce no exported bytes at all rather than bytes
     * labelled as qualified.
     */
    public function test_a_disqualified_run_exports_nothing(): void
    {
        $preflight = (string) file_get_contents($this->root() . '/scripts/release-check.php');

        $disqualified = strpos($preflight, 'does NOT qualify');
        $export = strpos($preflight, 'Exported the qualified artifact to');

        $this->assertIsInt($disqualified);
        $this->assertIsInt($export);

        $this->assertLessThan(
            $export,
            $disqualified,
            'the export must come after the disqualifier exit, or an --allow run would produce a release artifact'
        );
    }

    public function test_the_preflight_requires_an_annotated_tag(): void
    {
        $preflight = (string) file_get_contents($this->root() . '/scripts/release-check.php');

        $this->assertStringContainsString('cat-file -t', $preflight);
        $this->assertStringContainsString(
            "=== 'tag'",
            $preflight,
            'a lightweight tag records no tagger, date or message and must be refused'
        );
    }

    public function test_the_preflight_refuses_candidate_notes_once_tagged(): void
    {
        $preflight = (string) file_get_contents($this->root() . '/scripts/release-check.php');

        $this->assertStringContainsString('still describes itself as a release candidate', $preflight);
    }

    public function test_the_release_preflight_is_actually_invoked(): void
    {
        $contents = (string) file_get_contents($this->root() . self::RELEASE);

        $this->assertStringContainsString(
            'scripts/release-check.php',
            $contents,
            'the release workflow does not run the preflight'
        );

        $this->assertFileExists($this->root() . '/scripts/release-check.php');
    }

    public function test_no_publishing_secret_is_referenced(): void
    {
        $contents = (string) file_get_contents($this->root() . self::RELEASE);

        preg_match_all('/secrets\.([A-Z_]+)/', $contents, $matches);

        // GITHUB_TOKEN is issued per run and scoped by the permissions block.
        // Anything else would be a long-lived credential stored in this repo.
        $this->assertSame(
            ['GITHUB_TOKEN'],
            array_values(array_unique($matches[1])),
            'the release workflow references a secret other than GITHUB_TOKEN'
        );

        foreach (['PACKAGIST', 'COMPOSER_AUTH', 'NPM_TOKEN'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $contents);
        }
    }

    // ------------------------------------------------------------ preflight

    public function test_the_preflight_requires_a_version(): void
    {
        $result = $this->releaseCheck([]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Missing --version', $result['output']);
    }

    public function test_the_preflight_rejects_a_non_canonical_version(): void
    {
        foreach (['2.0.0', 'v2.0', 'v2.0.0-rc1', 'v2.0.0+build.1', 'release-2'] as $candidate) {
            $result = $this->releaseCheck(['--version=' . $candidate]);

            $this->assertSame(1, $result['code'], "{$candidate} must be refused");
            $this->assertStringContainsString('vMAJOR.MINOR.PATCH', $result['output']);
        }
    }

    public function test_the_preflight_accepts_the_canonical_form(): void
    {
        // Proves the rejection above is about the shape and not a blanket
        // refusal: a well-formed version gets past the version check and fails
        // later, on the missing documents.
        $result = $this->releaseCheck(['--version=v99.99.99', '--allow-dirty']);

        $this->assertSame(1, $result['code']);
        $this->assertStringNotContainsString('vMAJOR.MINOR.PATCH', $result['output']);
        $this->assertStringContainsString('docs/releases/99.99.99.md', $result['output']);
    }

    /**
     * Missing documents are terminal. The archive must not even be built —
     * nothing an installability check could report would make a release
     * without notes publishable.
     */
    public function test_missing_documents_stop_the_preflight_before_the_archive(): void
    {
        $result = $this->releaseCheck(['--version=v99.99.99', '--allow-dirty']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Artifact NOT inspected', $result['output']);
        $this->assertStringContainsString('CHANGELOG.md has no "## [99.99.99]" section', $result['output']);
    }

    /**
     * The escape hatches must never produce a passing run.
     */
    public function test_the_allow_flags_cannot_qualify_a_release(): void
    {
        $source = (string) file_get_contents($this->root() . '/scripts/release-check.php');

        $this->assertMatchesRegularExpression(
            '/if \(\$disqualifiers !== \[\]\) \{.*?exit\(1\);/s',
            $source,
            'a run carrying a disqualifier must exit non-zero'
        );

        $this->assertStringContainsString(
            '--allow-offline was passed, so installability was not proven.',
            $source,
            'skipping installability must disqualify the run'
        );
    }

    public function test_the_release_notes_for_the_candidate_version_exist(): void
    {
        $notes = $this->root() . '/docs/releases/2.0.0.md';

        $this->assertFileExists($notes);

        $contents = (string) file_get_contents($notes);

        $this->assertStringContainsString('release candidate', $contents);
        $this->assertStringContainsString('auth.peppers', $contents, 'the notes must name the config that blocks boot');
    }
}
