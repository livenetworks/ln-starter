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

    public function test_v2_email_and_confirmation_overrides_pass(): void
    {
        file_put_contents($this->directory . '/auth/magic.blade.php', "route('auth.magic.link.consume')");
        file_put_contents($this->directory . '/emails/magic-link.blade.php', '{{ $code }}');

        $this->assertSame([], (new AuthV2UpgradeAudit())->legacyPublishedViews($this->directory));
    }
}
