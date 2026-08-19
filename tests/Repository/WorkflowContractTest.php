<?php

namespace LiveNetworks\LnStarter\Tests\Repository;

use PHPUnit\Framework\TestCase;

/**
 * Every script the workflow runs must actually be in the repository.
 *
 * A gate that invokes a missing file does not fail loudly at the assertion it
 * was written for — it fails at "No such file or directory", or worse, a
 * `git add -A` quietly commits the deletion and the gate simply stops
 * existing. `scripts/consumer-upgrade.php` went missing from a working tree
 * once (an antivirus deny on that exact path); nothing in the suite would have
 * noticed if it had been committed that way.
 */
class WorkflowContractTest extends TestCase
{
    private const WORKFLOW = '/.github/workflows/tests.yml';

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Script paths the workflow invokes, normalised to repository-relative.
     *
     * The consumer job checks the repository out under `package/`, so its
     * invocations carry that prefix.
     *
     * @return list<string>
     */
    private function invokedScripts(): array
    {
        $workflow = (string) file_get_contents($this->root() . self::WORKFLOW);

        preg_match_all('#\bphp\s+(?:package/)?(scripts/[A-Za-z0-9._/-]+\.php)#', $workflow, $matches);

        $scripts = array_values(array_unique($matches[1]));
        sort($scripts);

        return $scripts;
    }

    public function test_the_workflow_invokes_the_scripts_this_repository_ships(): void
    {
        // Guards the extractor itself: a regex that silently matched nothing
        // would make every assertion below vacuous.
        $this->assertSame(
            [
                'scripts/consumer-install.php',
                'scripts/consumer-upgrade.php',
                'scripts/verify-artifact.php',
            ],
            $this->invokedScripts(),
            'the set of scripts the workflow drives has changed'
        );
    }

    public function test_every_script_the_workflow_runs_exists(): void
    {
        foreach ($this->invokedScripts() as $script) {
            $this->assertFileExists(
                $this->root() . '/' . $script,
                $script . ' is invoked by the workflow but is not in the working tree'
            );
        }
    }

    public function test_every_script_the_workflow_runs_is_tracked(): void
    {
        $listing = shell_exec('git -C ' . escapeshellarg($this->root()) . ' ls-files scripts');

        if (!is_string($listing) || trim($listing) === '') {
            $this->markTestSkipped('git file listing is unavailable.');
        }

        $tracked = array_map('trim', explode("\n", trim(str_replace("\r", '', $listing))));

        foreach ($this->invokedScripts() as $script) {
            $this->assertContains(
                $script,
                $tracked,
                $script . ' is invoked by the workflow but is not tracked by git'
            );
        }
    }

    /**
     * The harness the other two require must ship as well; it is pulled in by
     * `require_once`, so no workflow line names it.
     */
    public function test_the_shared_harness_ships(): void
    {
        $this->assertFileExists($this->root() . '/scripts/harness.php');

        foreach (['consumer-install.php', 'consumer-upgrade.php'] as $script) {
            $path = $this->root() . '/scripts/' . $script;

            if (!is_file($path)) {
                continue;
            }

            $this->assertStringContainsString(
                "require_once __DIR__ . '/harness.php';",
                (string) file_get_contents($path),
                $script . ' no longer loads the shared harness'
            );
        }
    }
}
