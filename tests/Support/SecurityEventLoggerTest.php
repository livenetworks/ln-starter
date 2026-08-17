<?php

namespace LiveNetworks\LnStarter\Tests\Support;

use Illuminate\Support\Facades\Log;
use LiveNetworks\LnStarter\Support\SecurityEventLogger;
use LiveNetworks\LnStarter\Tests\TestCase;

class SecurityEventLoggerTest extends TestCase
{
    public function test_sensitive_and_unknown_context_fields_are_dropped(): void
    {
        config()->set('ln-starter.logging.enabled', true);
        config()->set('ln-starter.logging.channel', null);
        Log::spy();

        (new SecurityEventLogger())->record('auth.magic.proof.rejected', [
            'attempt_id' => 'attempt-1',
            'outcome' => 'rejected',
            'email' => 'secret@example.test',
            'token' => 'raw-token',
            'code' => '123456',
            'cookie' => 'secret-cookie',
            'authorization' => 'Bearer secret',
        ]);

        Log::shouldHaveReceived('info')->once()->withArgs(
            function (string $event, array $context): bool {
                $this->assertSame('auth.magic.proof.rejected', $event);
                $this->assertSame('attempt-1', $context['attempt_id']);
                $this->assertSame('rejected', $context['outcome']);

                foreach (['email', 'token', 'code', 'cookie', 'authorization'] as $forbidden) {
                    $this->assertArrayNotHasKey($forbidden, $context);
                }

                return true;
            }
        );
    }
}
