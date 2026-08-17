<?php

namespace LiveNetworks\LnStarter\Tests\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LiveNetworks\LnStarter\Contracts\SecurityAuditSink;
use LiveNetworks\LnStarter\Security\Outcome;
use LiveNetworks\LnStarter\Security\SecurityEvent;
use LiveNetworks\LnStarter\Security\SecurityEventDispatcher;
use LiveNetworks\LnStarter\Security\SecurityEventName;
use LiveNetworks\LnStarter\Security\Sinks\DatabaseSink;
use LiveNetworks\LnStarter\Support\SecurityEventLogger;
use LiveNetworks\LnStarter\Tests\TestCase;
use RuntimeException;

class AuditSinkTest extends TestCase
{
    private function createAuditTable(): void
    {
        Schema::dropIfExists(DatabaseSink::TABLE);
        Schema::create(DatabaseSink::TABLE, function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('event_name', 96);
            $table->unsignedSmallInteger('schema_version');
            $table->timestamp('occurred_at');
            $table->string('severity', 16);
            $table->string('outcome', 16);
            $table->string('reason_code', 48)->nullable();
            $table->string('request_id', 128)->nullable();
            $table->string('correlation_id', 128)->nullable();
            $table->string('principal_key', 96)->nullable();
            $table->ulid('attempt_id')->nullable();
            $table->string('route', 191)->nullable();
            $table->string('http_method', 10)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('auth_method', 32)->nullable();
            $table->double('duration_ms')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_the_database_sink_is_not_registered_when_disabled(): void
    {
        config()->set('ln-starter.logging.database.enabled', false);
        $this->app->forgetInstance(SecurityEventDispatcher::class);

        $names = array_map(
            fn (SecurityAuditSink $sink) => $sink->name(),
            $this->app->make(SecurityEventDispatcher::class)->sinks()
        );

        $this->assertSame(['log'], $names);
    }

    public function test_a_disabled_database_sink_writes_nothing(): void
    {
        $this->createAuditTable();
        config()->set('ln-starter.logging.database.enabled', false);

        $this->app->make(SecurityEventLogger::class)
            ->event(SecurityEventName::SESSION_CREATED, Outcome::Success);

        $this->assertSame(0, DB::table(DatabaseSink::TABLE)->count());
    }

    public function test_an_enabled_database_sink_writes_a_sanitized_record(): void
    {
        $this->createAuditTable();
        config()->set('ln-starter.logging.database.enabled', true);
        // Rebuild the dispatcher so it picks up the enabled sink, without
        // refreshing the application (which would discard the config change
        // and the table created above).
        $this->app->forgetInstance(SecurityEventDispatcher::class);
        $this->app->forgetInstance(SecurityEventLogger::class);

        $this->assertContains(
            'database',
            array_map(
                fn (SecurityAuditSink $sink) => $sink->name(),
                $this->app->make(SecurityEventDispatcher::class)->sinks()
            )
        );

        $this->app->make(SecurityEventLogger::class)->event(
            eventName: SecurityEventName::SESSION_CREATED,
            outcome: Outcome::Success,
            context: ['consumed_via' => 'code', 'password' => 'hunter2'],
            principalKey: 'v1:' . str_repeat('a', 64),
            attemptId: '01JABCDEF0123456789ABCDEFG',
            durationMs: 3.5,
            authMethod: 'magic_code',
        );

        $row = DB::table(DatabaseSink::TABLE)->first();

        $this->assertNotNull($row);
        $this->assertSame(SecurityEventName::SESSION_CREATED, $row->event_name);
        $this->assertSame(SecurityEvent::SCHEMA_VERSION, (int) $row->schema_version);
        $this->assertSame(Outcome::Success->value, $row->outcome);
        $this->assertSame('magic_code', $row->auth_method);
        $this->assertSame(3.5, (float) $row->duration_ms);
        $this->assertNotNull($row->request_id);

        $context = json_decode((string) $row->context, true);
        $this->assertSame('code', $context['consumed_via']);
        $this->assertArrayNotHasKey('password', $context);
        $this->assertStringNotContainsString('hunter2', (string) $row->context);
    }

    public function test_a_failing_sink_does_not_propagate(): void
    {
        Log::spy();
        $dispatcher = $this->app->make(SecurityEventDispatcher::class);
        $dispatcher->extend(new ExplodingSink());

        // No exception must escape: a broken audit destination can never be
        // allowed to turn a successful login into an HTTP 500.
        $event = $this->app->make(SecurityEventLogger::class)
            ->event(SecurityEventName::SESSION_CREATED, Outcome::Success);

        $this->assertNotNull($event);
    }

    public function test_a_sink_failure_reports_through_the_fallback_channel_without_the_payload(): void
    {
        Log::spy();
        $this->app->make(SecurityEventDispatcher::class)->extend(new ExplodingSink());

        $this->app->make(SecurityEventLogger::class)->event(
            eventName: SecurityEventName::SESSION_CREATED,
            outcome: Outcome::Success,
            context: ['consumed_via' => 'code'],
        );

        Log::shouldHaveReceived('error')
            ->atLeast()->once()
            ->withArgs(function (string $message, array $context): bool {
                $this->assertSame('Security audit sink failed.', $message);
                $this->assertSame('exploding', $context['sink']);
                $this->assertSame(RuntimeException::class, $context['throwable_class']);

                // The throwable message is deliberately absent: it can echo
                // whatever the sink was handed.
                $this->assertStringNotContainsString('boom', json_encode($context));

                return true;
            });
    }

    public function test_a_sink_failure_emits_a_minimal_sink_failed_event_to_healthy_sinks(): void
    {
        Log::spy();
        $dispatcher = $this->app->make(SecurityEventDispatcher::class);
        $recorder = new RecordingSink();
        $dispatcher->extend(new ExplodingSink());
        $dispatcher->extend($recorder);

        $this->app->make(SecurityEventLogger::class)
            ->event(SecurityEventName::SESSION_CREATED, Outcome::Success);

        $names = array_map(fn (SecurityEvent $e) => $e->eventName, $recorder->events);

        $this->assertContains(SecurityEventName::SESSION_CREATED, $names);
        $this->assertContains(SecurityEventName::SINK_FAILED, $names);
    }

    /**
     * Reporting a failure must not itself be able to spiral: if every sink is
     * broken, the dispatcher reports once and stops.
     */
    public function test_a_universally_failing_sink_set_does_not_recurse(): void
    {
        Log::spy();
        $dispatcher = $this->app->make(SecurityEventDispatcher::class);
        $first = new ExplodingSink('exploding-one');
        $second = new ExplodingSink('exploding-two');
        $dispatcher->extend($first);
        $dispatcher->extend($second);

        $this->app->make(SecurityEventLogger::class)
            ->event(SecurityEventName::SESSION_CREATED, Outcome::Success);

        // Bounded: two primary failures, each retried against the one other
        // sink exactly once. Without the guard this would not terminate.
        $this->assertLessThanOrEqual(6, $first->calls + $second->calls);
    }
}

class ExplodingSink implements SecurityAuditSink
{
    public int $calls = 0;

    public function __construct(private readonly string $name = 'exploding')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function write(SecurityEvent $event): void
    {
        $this->calls++;

        throw new RuntimeException('boom: payload that must never be logged');
    }
}

class RecordingSink implements SecurityAuditSink
{
    /** @var list<SecurityEvent> */
    public array $events = [];

    public function name(): string
    {
        return 'recording';
    }

    public function write(SecurityEvent $event): void
    {
        $this->events[] = $event;
    }
}
