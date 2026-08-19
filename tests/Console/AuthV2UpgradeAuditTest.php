<?php

namespace LiveNetworks\LnStarter\Tests\Console;

use LiveNetworks\LnStarter\Support\AuthV2UpgradeAudit;
use PHPUnit\Framework\TestCase;

class AuthV2UpgradeAuditTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ln-auth-audit-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/auth', 0700, true);
        mkdir($this->directory . '/emails', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory . '/auth', $this->directory . '/emails'] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_legacy_wait_and_route_overrides_are_reported(): void
    {
        file_put_contents($this->directory . '/auth/magic_wait.blade.php', '/magic/status');
        file_put_contents($this->directory . '/auth/magic.blade.php', "route('auth.magic.consume')");

        $findings = (new AuthV2UpgradeAudit())->legacyPublishedViews($this->directory);

        $this->assertCount(2, $findings);
    }

    /**
     * magic_success.blade.php is the case the content scan cannot catch: the
     * shipped v1 copy references no removed route and no legacy field, so a
     * consumer who published it unchanged would keep it forever as dead state
     * while the audit reported nothing.
     */
    public function test_a_removed_view_with_no_legacy_markers_is_still_reported(): void
    {
        // Deliberately innocuous: nothing here matches a legacy pattern.
        file_put_contents(
            $this->directory . '/auth/magic_success.blade.php',
            '@extends("layouts._auth")' . PHP_EOL . '@section("content")<p>Signed in.</p>@endsection'
        );

        $findings = (new AuthV2UpgradeAudit())->legacyPublishedViews($this->directory);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('magic_success.blade.php', $findings[0]);
    }

    public function test_both_removed_views_are_reported_once_each(): void
    {
        file_put_contents($this->directory . '/auth/magic_wait.blade.php', '/magic/status');
        file_put_contents($this->directory . '/auth/magic_success.blade.php', 'nothing legacy here');

        $findings = (new AuthV2UpgradeAudit())->legacyPublishedViews($this->directory);

        // magic_wait matches both the name rule and the content rule; it must
        // not be reported twice.
        $this->assertSame($findings, array_values(array_unique($findings)));
        $this->assertCount(2, $findings);
    }

    public function test_v2_email_and_confirmation_overrides_pass(): void
    {
        file_put_contents($this->directory . '/auth/magic.blade.php', "route('auth.magic.link.consume')");
        file_put_contents($this->directory . '/emails/magic-link.blade.php', '{{ $code }}');

        $this->assertSame([], (new AuthV2UpgradeAudit())->legacyPublishedViews($this->directory));
    }
}
