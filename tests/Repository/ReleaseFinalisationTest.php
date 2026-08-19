<?php

namespace LiveNetworks\LnStarter\Tests\Repository;

use PHPUnit\Framework\TestCase;

/**
 * The finalisation gate, exercised as behaviour.
 *
 * Asserting that release-check.php *contains* `checkdate(` proves nothing: the
 * call could be unreachable, or wired to the wrong variable, and the assertion
 * would stay green. These tests run the real script against fixture documents
 * and read its verdicts.
 *
 * The fixture deliberately has no composer.json, so the manifest check fails
 * and the run short-circuits before building an archive. Every document gate
 * has already run and reported by that point, which is what is under test.
 */
class ReleaseFinalisationTest extends TestCase
{
    private const DATE = '2026-08-20';

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = sys_get_temp_dir() . '/ln-final-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/docs/releases', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([
            $this->fixture . '/docs/releases/2.0.0.md',
            $this->fixture . '/CHANGELOG.md',
            $this->fixture . '/UPGRADE.md',
        ] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach ([
            $this->fixture . '/docs/releases',
            $this->fixture . '/docs',
            $this->fixture,
        ] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        parent::tearDown();
    }

    /**
     * Writes a finalised set of documents, then applies the given overrides.
     *
     * @param  array{notes?:string, changelog?:string, upgrade?:string}  $overrides
     */
    private function writeDocuments(array $overrides = []): void
    {
        $date = self::DATE;

        // The notes must clear the length floor the preflight applies, so the
        // body is padded with something plausible rather than a stub.
        $notes = $overrides['notes'] ?? <<<MD
        # LN-Starter 2.0.0 — {$date}

        Auth v2 replaces the polling magic-login flow with a single-use,
        row-locked link-plus-code state machine that authenticates a normal
        Laravel web session. The security event pipeline gains a versioned
        envelope, a constrained reason-code vocabulary and pseudonymous
        principal identifiers.

        ## Breaking changes

        Route names auth.magic.show and auth.magic.consume are removed, the
        auth peppers are now required configuration, and the published wait and
        success views are gone.

        ## Upgrade

        See UPGRADE.md for the full procedure, which must be followed in order.
        MD;

        $changelog = $overrides['changelog'] ?? <<<MD
        # Changelog

        ## [2.0.0] — {$date}

        ### Added
        - Auth v2 state machine

        ## [1.2.1] — 2026-06-02

        ### Fixed
        - Forget the locale route parameter after consuming it
        MD;

        $upgrade = $overrides['upgrade'] ?? <<<MD
        # Upgrade notes

        ## 2.0.0 — {$date}

        Set LN_AUTH_PEPPER before composer require.
        MD;

        file_put_contents($this->fixture . '/docs/releases/2.0.0.md', $notes);
        file_put_contents($this->fixture . '/CHANGELOG.md', $changelog);
        file_put_contents($this->fixture . '/UPGRADE.md', $upgrade);
    }

    private function check(): string
    {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/release-check.php')
            . ' ' . escapeshellarg('--root=' . $this->fixture)
            . ' --version=v2.0.0 --allow-dirty --allow-offline --require-final';

        exec($command . ' 2>&1', $lines);

        return implode("\n", $lines);
    }

    /** Assert a named step reported ok. */
    private function assertStepPassed(string $label, string $output): void
    {
        $this->assertMatchesRegularExpression(
            '/' . preg_quote($label, '/') . '\.+ ok/',
            $output,
            "expected '{$label}' to pass:\n" . $output
        );
    }

    private function assertStepFailed(string $label, string $output): void
    {
        $this->assertMatchesRegularExpression(
            '/' . preg_quote($label, '/') . '\.+ FAIL/',
            $output,
            "expected '{$label}' to fail:\n" . $output
        );
    }

    public function test_fully_finalised_documents_pass_every_document_gate(): void
    {
        $this->writeDocuments();

        $output = $this->check();

        $this->assertStepPassed('Release notes are finalised', $output);
        $this->assertStepPassed('Changelog section is finalised', $output);
        $this->assertStepPassed('Upgrade notes are finalised', $output);
        $this->assertStepPassed('Release date agrees across documents', $output);
    }

    /**
     * The entries in the active section are prose about what changed, and may
     * legitimately contain the very words the status block may not. This
     * release's own changelog describes handling "release candidate" wording
     * and "Unreleased" sections; scanning the whole section would make it
     * undatable by its own description of itself.
     */
    public function test_active_entries_may_use_the_words_the_status_block_may_not(): void
    {
        $date = self::DATE;

        $this->writeDocuments([
            'changelog' => "# Changelog

## [2.0.0] — {$date}

"
                . "### Fixed
"
                . "- Refuse a release whose notes still say \"release candidate\"
"
                . "- Fold the \"Unreleased\" upgrade sections into this release
"
                . "- Stop claiming the changelog is \"not tagged\" once it is dated
",
        ]);

        $output = $this->check();

        $this->assertStepPassed('Changelog section is finalised', $output);
    }

    /**
     * The gate matches dated headings with `$` in /m mode, which matches before
     * a line feed but not before a carriage return. On a CRLF checkout — the
     * normal state of this repository on Windows — every heading pattern was
     * unmatchable and finalisation was permanently impossible. Fixture
     * documents are written with LF, so only the real files exposed it.
     */
    public function test_documents_with_crlf_line_endings_are_accepted(): void
    {
        $this->writeDocuments();

        foreach (['/CHANGELOG.md', '/UPGRADE.md', '/docs/releases/2.0.0.md'] as $relative) {
            $path = $this->fixture . $relative;
            $contents = (string) file_get_contents($path);

            file_put_contents($path, str_replace("
", "
", str_replace("
", "
", $contents)));
        }

        $output = $this->check();

        $this->assertStepPassed('Release notes are finalised', $output);
        $this->assertStepPassed('Changelog section is finalised', $output);
        $this->assertStepPassed('Upgrade notes are finalised', $output);
        $this->assertStepPassed('Release date agrees across documents', $output);
    }

    public function test_an_impossible_date_is_refused(): void
    {
        $this->writeDocuments([
            'changelog' => "# Changelog\n\n## [2.0.0] — 2026-02-31\n\n### Added\n- Auth v2\n",
        ]);

        $output = $this->check();

        $this->assertStepFailed('Changelog section is finalised', $output);
        $this->assertStringContainsString('impossible date', $output);
    }

    public function test_disagreeing_dates_are_refused(): void
    {
        $this->writeDocuments([
            'changelog' => "# Changelog\n\n## [2.0.0] — 2026-08-21\n\n### Added\n- Auth v2\n",
        ]);

        $output = $this->check();

        $this->assertStepFailed('Release date agrees across documents', $output);
        $this->assertStringContainsString('2026-08-20', $output);
        $this->assertStringContainsString('2026-08-21', $output);
    }

    /**
     * The heading is not the section. A dated heading above a body still
     * claiming there is no tag is the contradiction this gate exists to catch.
     */
    public function test_a_contradicting_changelog_body_is_refused(): void
    {
        $date = self::DATE;

        $this->writeDocuments([
            'changelog' => "# Changelog\n\n## [2.0.0] — {$date}\n\n"
                . "> Not tagged. The date is written when the tag is created.\n\n"
                . "### Added\n- Auth v2\n",
        ]);

        $output = $this->check();

        $this->assertStepFailed('Changelog section is finalised', $output);
        $this->assertStringContainsString('still says: not tagged', $output);
    }

    /**
     * The scan must be scoped to the active section: historical entries may
     * legitimately contain these words, and scanning the whole file would make
     * every release after the first unreleasable.
     */
    public function test_historical_sections_may_mention_candidates(): void
    {
        $date = self::DATE;

        $this->writeDocuments([
            'changelog' => "# Changelog\n\n## [2.0.0] — {$date}\n\n### Added\n- Auth v2\n\n"
                . "## [1.2.1] — 2026-06-02\n\n"
                . "This section was published from a release candidate and was unreleased\n"
                . "for a while. Not tagged until the day it shipped.\n",
        ]);

        $output = $this->check();

        $this->assertStepPassed('Changelog section is finalised', $output);
    }

    public function test_an_unreleased_section_in_the_upgrade_notes_is_refused(): void
    {
        $date = self::DATE;

        $this->writeDocuments([
            'upgrade' => "# Upgrade notes\n\n## 2.0.0 — {$date}\n\nSet LN_AUTH_PEPPER.\n\n"
                . "## Unreleased — security audit logging\n\nThese ship in 2.0.0.\n",
        ]);

        $output = $this->check();

        $this->assertStepFailed('Upgrade notes are finalised', $output);
        $this->assertStringContainsString('Unreleased', $output);
    }

    public function test_undated_release_notes_are_refused(): void
    {
        // Candidate wording removed, date never added. Dropping the banner
        // alone must not be enough to pass.
        $this->writeDocuments([
            'notes' => "# LN-Starter 2.0.0

" . str_repeat(
                'Auth v2 replaces the polling magic-login flow with a single-use, '
                . 'row-locked link-plus-code state machine. ',
                8
            ),
        ]);

        $output = $this->check();

        $this->assertStepFailed('Release notes are finalised', $output);
        $this->assertStringContainsString('has no dated heading', $output);
    }
}
