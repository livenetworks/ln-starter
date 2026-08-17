<?php

namespace LiveNetworks\LnStarter\Tests\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LiveNetworks\LnStarter\Security\Pseudonymizer;
use LiveNetworks\LnStarter\Security\Sinks\DatabaseSink;
use LiveNetworks\LnStarter\Support\SecurityObservabilityConfiguration;
use LiveNetworks\LnStarter\Tests\TestCase;
use RuntimeException;

class ObservabilityReadinessTest extends TestCase
{
    private function readiness(): SecurityObservabilityConfiguration
    {
        return $this->app->make(SecurityObservabilityConfiguration::class);
    }

    public function test_the_default_configuration_is_ready(): void
    {
        $this->readiness()->validate();

        $this->addToAssertionCount(1);
    }

    public function test_disabled_logging_skips_every_check(): void
    {
        config()->set('ln-starter.logging.enabled', false);
        config()->set('ln-starter.logging.max_context_depth', 'nonsense');

        $this->readiness()->validate(true);

        $this->addToAssertionCount(1);
    }

    public function test_out_of_range_sanitizer_bounds_are_rejected(): void
    {
        config()->set('ln-starter.logging.max_context_depth', 999);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('max_context_depth');

        $this->readiness()->validate();
    }

    public function test_a_malformed_request_id_header_is_rejected(): void
    {
        config()->set('ln-starter.logging.request_id_header', 'Bad Header: value');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('request_id_header');

        $this->readiness()->validate();
    }

    public function test_an_unresolvable_log_channel_is_rejected(): void
    {
        config()->set('ln-starter.logging.channel', 'channel-that-does-not-exist');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve');

        $this->readiness()->validate();
    }

    public function test_production_rejects_the_placeholder_pseudonym_key(): void
    {
        $this->app['env'] = 'production';
        config()->set('ln-starter.logging.pseudonym.keys.v1', Pseudonymizer::INSECURE_PLACEHOLDER . str_repeat('x', 40));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('shipped placeholder');

        $this->readiness()->validate();
    }

    public function test_an_enabled_database_sink_without_its_table_is_rejected(): void
    {
        Schema::dropIfExists(DatabaseSink::TABLE);
        config()->set('ln-starter.logging.database.enabled', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->readiness()->validate(true);
    }

    public function test_an_out_of_range_retention_window_is_rejected(): void
    {
        config()->set('ln-starter.logging.database.enabled', true);
        config()->set('ln-starter.logging.database.retention_days', 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('retention_days');

        $this->readiness()->validate();
    }

    public function test_an_enabled_database_sink_with_its_table_passes(): void
    {
        config()->set('ln-starter.logging.database.enabled', true);

        Schema::dropIfExists(DatabaseSink::TABLE);
        Schema::create(DatabaseSink::TABLE, function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamp('occurred_at');
        });

        $this->readiness()->validate(true);

        $this->addToAssertionCount(1);
    }

    public function test_the_summary_never_exposes_key_material(): void
    {
        $secret = 'base64:' . base64_encode(str_repeat('S', 32));
        config()->set('ln-starter.logging.pseudonym.keys.v1', $secret);

        $summary = $this->readiness()->summary();
        $serialized = json_encode($summary);

        $this->assertStringNotContainsString($secret, $serialized);
        $this->assertStringNotContainsString('SSSS', $serialized);
        $this->assertSame('v1', $summary['pseudonym version']);
    }

    /**
     * Rotation must keep the previous version usable: an operator adds a new
     * key, points `current` at it, and historical digests stay interpretable.
     */
    public function test_pseudonym_rotation_keeps_the_previous_version_resolvable(): void
    {
        $pseudonymizer = $this->app->make(Pseudonymizer::class);

        config()->set('ln-starter.logging.pseudonym.current', 'v1');
        config()->set('ln-starter.logging.pseudonym.keys', [
            'v1' => 'base64:' . base64_encode(str_repeat('a', 32)),
        ]);

        $before = $pseudonymizer->principal('user:7');
        $this->assertStringStartsWith('v1:', $before);

        config()->set('ln-starter.logging.pseudonym.current', 'v2');
        config()->set('ln-starter.logging.pseudonym.keys', [
            'v1' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'v2' => 'base64:' . base64_encode(str_repeat('b', 32)),
        ]);

        $after = $pseudonymizer->principal('user:7');

        $this->assertStringStartsWith('v2:', $after);
        $this->assertNotSame($before, $after);

        // The old key still resolves, so historical rows remain interpretable
        // and readiness does not fail during the overlap window.
        $this->assertNotSame('', $pseudonymizer->key('v1'));
        $this->readiness()->validate();
    }

    public function test_purposes_are_separated(): void
    {
        $pseudonymizer = $this->app->make(Pseudonymizer::class);

        $this->assertNotSame(
            $pseudonymizer->forPurpose('principal', 'a@example.test'),
            $pseudonymizer->forPurpose('rate', 'a@example.test')
        );
    }

    public function test_a_missing_referenced_key_fails_closed(): void
    {
        config()->set('ln-starter.logging.pseudonym.current', 'v9');
        config()->set('app.key', '');

        $this->expectException(RuntimeException::class);

        $this->app->make(Pseudonymizer::class)->assertConfigured();
    }
}
