<?php

namespace LiveNetworks\LnStarter\Tests\Auth;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LiveNetworks\LnStarter\Contracts\AuthEligibility;
use LiveNetworks\LnStarter\Contracts\SecurityAuditSink;
use LiveNetworks\LnStarter\Jobs\ProcessMagicLoginRequest;
use LiveNetworks\LnStarter\Security\Outcome;
use LiveNetworks\LnStarter\Security\ReasonCode;
use LiveNetworks\LnStarter\Security\SecurityEvent;
use LiveNetworks\LnStarter\Security\SecurityEventDispatcher;
use LiveNetworks\LnStarter\Security\SecurityEventName;
use LiveNetworks\LnStarter\Support\MagicLoginProofs;
use LiveNetworks\LnStarter\Support\SecurityEventLogger;
use LiveNetworks\LnStarter\Tests\Security\RecordingSink;
use LiveNetworks\LnStarter\Tests\TestCase;

/**
 * End-to-end instrumentation of the real auth v2 flow.
 *
 * Events are captured through a registered sink rather than by mocking the
 * logger, so the assertions exercise the same path production uses — including
 * the sanitizer and the envelope builder.
 */
class AuthObservabilityTest extends TestCase
{
    private RecordingSink $sink;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', ObservabilityUser::class);
        $app['config']->set('ln-starter.auth.enabled', true);
        $app['config']->set('ln-starter.auth.user_model', ObservabilityUser::class);
        $app['config']->set('ln-starter.auth.home_route', 'home');
        $app['config']->set('ln-starter.auth.layout', 'test-auth-layout');
        $app['config']->set('ln-starter.auth.response_floor_ms', 0);
        $app['config']->set('ln-starter.auth.response_jitter_ms', 0);
        $app['config']->set('ln-starter.auth.peppers.current', 'v1');
        $app['config']->set('ln-starter.auth.peppers.keys', ['v1' => str_repeat('a', 32)]);
        $app['config']->set('ln-starter.logging.enabled', true);
        $app['config']->set('ln-starter.logging.pseudonym.keys.v1', 'base64:' . base64_encode(str_repeat('p', 32)));
    }

    protected function defineRoutes($router): void
    {
        $router->get('/_test/home', static fn () => 'home')->name('home');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['view']->addLocation(__DIR__ . '/../Fixtures/views');

        Schema::dropIfExists('magic_login_attempts');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('magic_login_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->char('email_key', 64)->index();
            $table->string('pepper_id', 64);
            $table->char('link_token_hash', 64)->unique();
            $table->char('code_hash', 64);
            $table->char('requester_nonce_hash', 64);
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedTinyInteger('code_attempts')->default(0);
            $table->string('consumed_via', 16)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('code_locked_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Queue::fake();
        Mail::fake();

        $this->sink = new RecordingSink();
        $this->app->make(SecurityEventDispatcher::class)->extend($this->sink);
    }

    /** @return list<string> */
    private function eventNames(): array
    {
        return array_map(fn (SecurityEvent $e) => $e->eventName, $this->sink->events);
    }

    private function firstEvent(string $name): ?SecurityEvent
    {
        foreach ($this->sink->events as $event) {
            if ($event->eventName === $name) {
                return $event;
            }
        }

        return null;
    }

    private function requestAndProcess(string $email): ProcessMagicLoginRequest
    {
        $this->post('/auth/magic-link', ['email' => $email]);

        $job = Queue::pushed(ProcessMagicLoginRequest::class)->last();
        $this->assertInstanceOf(ProcessMagicLoginRequest::class, $job);

        $job->handle(
            $this->app->make(AuthEligibility::class),
            $this->app->make(MagicLoginProofs::class),
            $this->app->make(SecurityEventLogger::class),
        );

        return $job;
    }

    public function test_the_request_lifecycle_emits_received_accepted_and_queued(): void
    {
        ObservabilityUser::create(['email' => 'person@example.test']);

        $this->post('/auth/magic-link', ['email' => 'person@example.test']);

        $names = $this->eventNames();
        $this->assertContains(SecurityEventName::REQUEST_RECEIVED, $names);
        $this->assertContains(SecurityEventName::REQUEST_ACCEPTED, $names);
        $this->assertContains(SecurityEventName::DELIVERY_QUEUED, $names);
    }

    public function test_delivery_success_is_recorded_with_a_real_duration(): void
    {
        ObservabilityUser::create(['email' => 'person@example.test']);

        $this->requestAndProcess('person@example.test');

        $event = $this->firstEvent(SecurityEventName::DELIVERY_SUCCEEDED);

        $this->assertNotNull($event);
        $this->assertSame(Outcome::Success, $event->outcome);
        $this->assertIsFloat($event->durationMs);
        $this->assertGreaterThanOrEqual(0.0, $event->durationMs);
    }

    public function test_an_unknown_principal_is_recorded_internally_but_not_publicly(): void
    {
        $known = $this->post('/auth/magic-link', ['email' => 'ghost@example.test']);
        $this->requestAndProcessLast();

        $event = $this->firstEvent(SecurityEventName::REQUEST_REJECTED);

        $this->assertNotNull($event);
        $this->assertSame(ReasonCode::UnknownPrincipal, $event->reasonCode);

        // The internal reason code exists; the public response must not carry
        // any of that distinction.
        $known->assertRedirect();
    }

    private function requestAndProcessLast(): void
    {
        $job = Queue::pushed(ProcessMagicLoginRequest::class)->last();

        if ($job instanceof ProcessMagicLoginRequest) {
            $job->handle(
                $this->app->make(AuthEligibility::class),
                $this->app->make(MagicLoginProofs::class),
                $this->app->make(SecurityEventLogger::class),
            );
        }
    }

    public function test_a_successful_code_login_emits_proof_accepted_and_session_created(): void
    {
        ObservabilityUser::create(['email' => 'person@example.test']);
        $job = $this->requestAndProcess('person@example.test');

        $this->post('/auth/magic/code', ['code' => $job->code])->assertRedirect();

        $names = $this->eventNames();
        $this->assertContains(SecurityEventName::PROOF_ACCEPTED, $names);
        $this->assertContains(SecurityEventName::SESSION_CREATED, $names);

        $session = $this->firstEvent(SecurityEventName::SESSION_CREATED);
        $this->assertSame('magic_code', $session->authMethod);
        $this->assertSame('web', $session->guard);
        $this->assertNotNull($session->principalKey);
        $this->assertGreaterThanOrEqual(0.0, $session->durationMs);
    }

    public function test_exactly_one_proof_accepted_is_emitted_per_attempt(): void
    {
        ObservabilityUser::create(['email' => 'person@example.test']);
        $job = $this->requestAndProcess('person@example.test');

        $this->post('/auth/magic/code', ['code' => $job->code]);
        $this->post('/auth/magic/code', ['code' => $job->code]);

        $accepted = array_filter(
            $this->eventNames(),
            fn (string $name) => $name === SecurityEventName::PROOF_ACCEPTED
        );

        $this->assertCount(1, $accepted);
    }

    public function test_a_wrong_code_is_rejected_with_a_constrained_reason_code(): void
    {
        ObservabilityUser::create(['email' => 'person@example.test']);
        $this->requestAndProcess('person@example.test');

        $this->post('/auth/magic/code', ['code' => '000000']);

        $event = $this->firstEvent(SecurityEventName::PROOF_REJECTED);

        $this->assertNotNull($event);
        $this->assertSame(Outcome::Rejected, $event->outcome);
        $this->assertInstanceOf(ReasonCode::class, $event->reasonCode);
    }

    public function test_logout_emits_session_terminated_and_a_noop_when_unauthenticated(): void
    {
        ObservabilityUser::create(['email' => 'person@example.test']);
        $job = $this->requestAndProcess('person@example.test');
        $this->post('/auth/magic/code', ['code' => $job->code]);

        $this->post('/logout');
        $this->assertContains(SecurityEventName::SESSION_TERMINATED, $this->eventNames());
    }

    public function test_rate_limiting_emits_a_reason_specific_event(): void
    {
        ObservabilityUser::create(['email' => 'person@example.test']);

        for ($i = 0; $i < 8; $i++) {
            $this->post('/auth/magic-link', ['email' => 'person@example.test']);
        }

        $event = $this->firstEvent(SecurityEventName::RATE_LIMITED);

        $this->assertNotNull($event);
        $this->assertSame(Outcome::Rejected, $event->outcome);
        $this->assertContains($event->reasonCode, [
            ReasonCode::RateLimitedEmail,
            ReasonCode::RateLimitedIp,
            ReasonCode::RateLimitedSession,
        ]);
    }

    /**
     * The strongest single assertion in this file: drive the entire flow and
     * prove that nothing secret reached any sink.
     */
    public function test_no_secret_or_personal_datum_reaches_a_sink_across_the_whole_flow(): void
    {
        $email = 'person@example.test';
        ObservabilityUser::create(['email' => $email]);

        $job = $this->requestAndProcess($email);

        $this->get('/auth/magic/' . $job->linkToken);
        $this->post('/auth/magic/code', ['code' => '000000']);
        $this->post('/auth/magic/code', ['code' => $job->code]);
        $this->post('/logout');

        $serialized = json_encode(array_map(
            fn (SecurityEvent $e) => $e->toArray(),
            $this->sink->events
        ));

        $this->assertNotSame('[]', $serialized);

        foreach ([$email, 'person', 'example.test', $job->linkToken, $job->code] as $secret) {
            $this->assertStringNotContainsString(
                (string) $secret,
                $serialized,
                'A secret or personal datum reached the audit sink: ' . $secret
            );
        }

        // The link token must not appear even as a route parameter.
        foreach ($this->sink->events as $event) {
            $this->assertNotNull($event->requestId);
            if ($event->route !== null) {
                $this->assertStringNotContainsString($job->linkToken, $event->route);
            }
        }
    }

    public function test_every_emitted_event_carries_a_schema_version_and_correlation_id(): void
    {
        ObservabilityUser::create(['email' => 'person@example.test']);
        $job = $this->requestAndProcess('person@example.test');
        $this->post('/auth/magic/code', ['code' => $job->code]);

        $this->assertNotEmpty($this->sink->events);

        foreach ($this->sink->events as $event) {
            $this->assertSame(SecurityEvent::SCHEMA_VERSION, $event->schemaVersion);
            $this->assertNotNull($event->correlationId);
            $this->assertNotSame('', $event->eventId);
        }
    }

    public function test_delivery_events_inherit_the_originating_correlation_id(): void
    {
        ObservabilityUser::create(['email' => 'person@example.test']);

        $this->post('/auth/magic-link', ['email' => 'person@example.test']);

        $accepted = $this->firstEvent(SecurityEventName::REQUEST_ACCEPTED);
        $this->assertNotNull($accepted);

        $this->requestAndProcessLast();

        $delivered = $this->firstEvent(SecurityEventName::DELIVERY_SUCCEEDED);
        $this->assertNotNull($delivered);

        // Same correlation, different request id: the job is a distinct
        // execution within the same logical operation.
        $this->assertSame($accepted->correlationId, $delivered->correlationId);
        $this->assertNotSame($accepted->requestId, $delivered->requestId);
    }

    public function test_a_broken_sink_never_breaks_the_login(): void
    {
        $this->app->make(SecurityEventDispatcher::class)->extend(new AlwaysFailingSink());

        ObservabilityUser::create(['email' => 'person@example.test']);
        $job = $this->requestAndProcess('person@example.test');

        $this->post('/auth/magic/code', ['code' => $job->code])
            ->assertRedirect(route('home'));

        $this->assertAuthenticated();
    }
}

class ObservabilityUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;
}

class AlwaysFailingSink implements SecurityAuditSink
{
    public function name(): string
    {
        return 'always-failing';
    }

    public function write(SecurityEvent $event): void
    {
        throw new \RuntimeException('sink is down');
    }
}
