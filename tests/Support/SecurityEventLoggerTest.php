<?php

namespace LiveNetworks\LnStarter\Tests\Support;

use Illuminate\Support\Facades\Log;
use LiveNetworks\LnStarter\Security\Outcome;
use LiveNetworks\LnStarter\Security\ReasonCode;
use LiveNetworks\LnStarter\Security\SecurityEvent;
use LiveNetworks\LnStarter\Security\SecurityEventName;
use LiveNetworks\LnStarter\Security\Severity;
use LiveNetworks\LnStarter\Support\SecurityEventLogger;
use LiveNetworks\LnStarter\Tests\TestCase;

class SecurityEventLoggerTest extends TestCase
{
    private function logger(): SecurityEventLogger
    {
        config()->set('ln-starter.logging.enabled', true);
        config()->set('ln-starter.logging.channel', null);

        return $this->app->make(SecurityEventLogger::class);
    }

    public function test_sensitive_and_unknown_context_fields_are_dropped(): void
    {
        $logger = $this->logger();
        Log::spy();

        $logger->record('auth.magic.proof.rejected', [
            'attempt_id' => 'attempt-1',
            'outcome' => 'rejected',
            'reason' => ReasonCode::InvalidCode->value,
            'email' => 'secret@example.test',
            'token' => 'raw-token',
            'code' => '123456',
            'cookie' => 'secret-cookie',
            'authorization' => 'Bearer secret',
            'password' => 'hunter2',
            'session_id' => 'abc',
            'made_up_field' => 'dropped',
        ]);

        Log::shouldHaveReceived('log')->once()->withArgs(
            function (string $level, string $event, array $envelope): bool {
                $this->assertSame(Severity::Warning->value, $level);
                $this->assertSame('auth.magic.proof.rejected', $event);

                // Promoted into real envelope fields.
                $this->assertSame('attempt-1', $envelope['attempt_id']);
                $this->assertSame(Outcome::Rejected->value, $envelope['outcome']);
                $this->assertSame(ReasonCode::InvalidCode->value, $envelope['reason_code']);

                $serialized = json_encode($envelope);
                foreach (['secret@example.test', 'raw-token', '123456', 'secret-cookie', 'Bearer secret', 'hunter2'] as $secret) {
                    $this->assertStringNotContainsString($secret, $serialized);
                }

                $this->assertArrayNotHasKey('made_up_field', $envelope['context']);

                return true;
            }
        );
    }

    public function test_every_event_carries_the_canonical_envelope(): void
    {
        $logger = $this->logger();
        Log::spy();

        $logger->event(
            eventName: SecurityEventName::SESSION_CREATED,
            outcome: Outcome::Success,
            context: ['auth_method' => 'magic_code'],
            attemptId: '01JABCDEF0123456789ABCDEFG',
            durationMs: 12.5,
            authMethod: 'magic_code',
            guard: 'web',
            statusCode: 302,
        );

        Log::shouldHaveReceived('log')->once()->withArgs(
            function (string $level, string $event, array $envelope): bool {
                foreach ([
                    'event_id', 'event_name', 'schema_version', 'occurred_at', 'severity',
                    'outcome', 'reason_code', 'request_id', 'correlation_id', 'environment',
                    'application', 'route', 'http_method', 'status_code', 'auth_method',
                    'guard', 'principal_key', 'attempt_id', 'duration_ms', 'context',
                ] as $field) {
                    $this->assertArrayHasKey($field, $envelope, "missing envelope field {$field}");
                }

                $this->assertSame(SecurityEvent::SCHEMA_VERSION, $envelope['schema_version']);
                $this->assertSame('magic_code', $envelope['auth_method']);
                $this->assertSame('web', $envelope['guard']);
                $this->assertSame(302, $envelope['status_code']);
                $this->assertSame(12.5, $envelope['duration_ms']);
                // RFC3339 with millisecond precision; UTC renders as "Z".
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}(Z|[+-]\d{2}:\d{2})$/',
                    $envelope['occurred_at']
                );

                return true;
            }
        );
    }

    public function test_a_negative_duration_is_clamped(): void
    {
        $logger = $this->logger();
        Log::spy();

        $logger->event(SecurityEventName::SESSION_TERMINATED, Outcome::Success, durationMs: -5.0);

        Log::shouldHaveReceived('log')->once()->withArgs(
            function (string $level, string $event, array $envelope): bool {
                $this->assertGreaterThanOrEqual(0.0, $envelope['duration_ms']);

                return true;
            }
        );
    }

    public function test_an_unknown_reason_string_collapses_to_unspecified(): void
    {
        $logger = $this->logger();
        Log::spy();

        $logger->record('auth.magic.proof.rejected', [
            'outcome' => 'rejected',
            'reason' => 'SQLSTATE[42000]: raw driver message with secrets',
        ]);

        Log::shouldHaveReceived('log')->once()->withArgs(
            function (string $level, string $event, array $envelope): bool {
                $this->assertSame(ReasonCode::Unspecified->value, $envelope['reason_code']);
                $this->assertStringNotContainsString('SQLSTATE', json_encode($envelope));

                return true;
            }
        );
    }

    public function test_a_user_id_becomes_a_versioned_pseudonymous_principal_key(): void
    {
        $logger = $this->logger();
        Log::spy();

        $logger->record(SecurityEventName::SESSION_CREATED, [
            'outcome' => 'success',
            'user_id' => 4242,
        ]);

        Log::shouldHaveReceived('log')->once()->withArgs(
            function (string $level, string $event, array $envelope): bool {
                $this->assertMatchesRegularExpression('/^v1:[0-9a-f]{64}$/', $envelope['principal_key']);
                $this->assertStringNotContainsString('4242', json_encode($envelope));

                return true;
            }
        );
    }

    public function test_logging_can_be_disabled_entirely(): void
    {
        $logger = $this->logger();
        config()->set('ln-starter.logging.enabled', false);
        Log::spy();

        $this->assertNull($logger->record(SecurityEventName::SESSION_CREATED, ['outcome' => 'success']));

        Log::shouldNotHaveReceived('log');
    }
}
