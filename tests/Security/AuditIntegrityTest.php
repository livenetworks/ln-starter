<?php

namespace LiveNetworks\LnStarter\Tests\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LiveNetworks\LnStarter\Contracts\AuthEligibility;
use LiveNetworks\LnStarter\Contracts\SecurityAuditSink;
use LiveNetworks\LnStarter\Jobs\ProcessMagicLoginRequest;
use LiveNetworks\LnStarter\Security\Outcome;
use LiveNetworks\LnStarter\Security\Pseudonymizer;
use LiveNetworks\LnStarter\Security\ReasonCode;
use LiveNetworks\LnStarter\Security\RequestContext;
use LiveNetworks\LnStarter\Security\SecurityEvent;
use LiveNetworks\LnStarter\Security\SecurityEventDispatcher;
use LiveNetworks\LnStarter\Security\SecurityEventName;
use LiveNetworks\LnStarter\Security\Sinks\DatabaseSink;
use LiveNetworks\LnStarter\Support\MagicLoginProofs;
use LiveNetworks\LnStarter\Support\SecurityEventLogger;
use LiveNetworks\LnStarter\Tests\TestCase;

/**
 * Regressions for defects found in review of the audit pipeline.
 *
 * Each test names the property that was actually broken, not just the symptom.
 */
class AuditIntegrityTest extends TestCase
{
    private RecordingSink $sink;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', IntegrityUser::class);
        $app['config']->set('ln-starter.auth.enabled', true);
        $app['config']->set('ln-starter.auth.user_model', IntegrityUser::class);
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
    private function names(): array
    {
        return array_map(fn (SecurityEvent $e) => $e->eventName, $this->sink->events);
    }

    private function find(string $name): ?SecurityEvent
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

        $job->handle(
            $this->app->make(AuthEligibility::class),
            $this->app->make(MagicLoginProofs::class),
            $this->app->make(SecurityEventLogger::class),
        );

        return $job;
    }

    /**
     * A throttled link open says nothing about the proof. Reporting it as a
     * replay put a false "this token was reused" claim into the audit trail
     * for a valid, still-pending link.
     */
    public function test_a_throttled_link_open_is_not_reported_as_a_replay(): void
    {
        IntegrityUser::create(['email' => 'person@example.test']);
        $job = $this->requestAndProcess('person@example.test');

        // Exhaust the per-proof link-open limit (10 in 15 minutes).
        for ($i = 0; $i < 12; $i++) {
            $this->get('/auth/magic/' . $job->linkToken);
        }

        $this->assertContains(SecurityEventName::RATE_LIMITED, $this->names());
        $this->assertNotContains(SecurityEventName::PROOF_REPLAYED, $this->names());

        $throttle = $this->find(SecurityEventName::RATE_LIMITED);
        $this->assertSame(Outcome::Rejected, $throttle->outcome);
        $this->assertSame('magic_link', $throttle->authMethod);

        // The attempt is untouched: throttling is not consumption.
        $this->assertDatabaseHas('magic_login_attempts', [
            'id' => $job->attemptId,
            'status' => 'pending',
        ]);
    }

    public function test_code_verification_throttling_emits_a_reason_specific_event(): void
    {
        IntegrityUser::create(['email' => 'person@example.test']);
        $this->requestAndProcess('person@example.test');

        for ($i = 0; $i < 14; $i++) {
            $this->post('/auth/magic/code', ['code' => '000000']);
        }

        $throttle = $this->find(SecurityEventName::RATE_LIMITED);

        $this->assertNotNull($throttle, 'Code throttling must be audited.');
        $this->assertSame('magic_code', $throttle->authMethod);
        $this->assertContains($throttle->reasonCode, [
            ReasonCode::RateLimitedEmail,
            ReasonCode::RateLimitedSession,
            ReasonCode::RateLimitedIp,
        ]);
    }

    public function test_link_confirmation_throttling_emits_an_event(): void
    {
        IntegrityUser::create(['email' => 'person@example.test']);
        $job = $this->requestAndProcess('person@example.test');

        $open = $this->get('/auth/magic/' . $job->linkToken);
        $context = basename(parse_url($open->headers->get('Location'), PHP_URL_PATH));

        for ($i = 0; $i < 8; $i++) {
            $this->post('/auth/magic/confirm/' . $context);
        }

        $throttle = $this->find(SecurityEventName::RATE_LIMITED);

        $this->assertNotNull($throttle);
        $this->assertSame('magic_link', $throttle->authMethod);
    }

    /**
     * name() is third-party code too. Calling it outside a guard let a sink
     * throw straight into the auth flow, defeating fail-open entirely.
     */
    public function test_a_sink_that_throws_from_name_does_not_break_the_login(): void
    {
        Log::spy();
        $this->app->make(SecurityEventDispatcher::class)->extend(new HostileNameSink());

        IntegrityUser::create(['email' => 'person@example.test']);
        $job = $this->requestAndProcess('person@example.test');

        $this->post('/auth/magic/code', ['code' => $job->code])
            ->assertRedirect(route('home'));

        $this->assertAuthenticated();
    }

    public function test_a_raw_identifier_passed_to_the_public_api_is_pseudonymized(): void
    {
        $logger = $this->app->make(SecurityEventLogger::class);

        $logger->event(
            eventName: 'app.custom.event',
            outcome: Outcome::Success,
            principalKey: 'person@example.test',
        );

        $event = $this->find('app.custom.event');

        $this->assertNotNull($event);
        $this->assertNotSame('person@example.test', $event->principalKey);
        $this->assertTrue(Pseudonymizer::isPseudonymKey($event->principalKey));
        $this->assertStringNotContainsString('person@example.test', json_encode($event->toArray()));
    }

    public function test_a_raw_identifier_in_record_context_is_pseudonymized(): void
    {
        $this->app->make(SecurityEventLogger::class)->record('app.custom.event', [
            'outcome' => 'success',
            'principal_key' => 'raw-email@example.test',
        ]);

        $event = $this->find('app.custom.event');

        $this->assertTrue(Pseudonymizer::isPseudonymKey($event->principalKey));
        $this->assertStringNotContainsString('raw-email@example.test', json_encode($event->toArray()));
    }

    /**
     * Both keys are promoted to validated envelope fields, so allowing them in
     * `context` would be a second, unvalidated way in.
     */
    public function test_principal_key_and_user_id_cannot_ride_along_inside_context(): void
    {
        $this->app->make(SecurityEventLogger::class)->event(
            eventName: 'app.custom.event',
            outcome: Outcome::Success,
            context: [
                'principal_key' => 'person@example.test',
                'user_id' => 4242,
                'reason' => 'kept',
            ],
        );

        $event = $this->find('app.custom.event');

        $this->assertSame(['reason' => 'kept'], $event->context);
        $this->assertStringNotContainsString('person@example.test', json_encode($event->toArray()));
    }

    public function test_the_database_sink_persists_the_full_envelope(): void
    {
        Schema::dropIfExists(DatabaseSink::TABLE);
        $migration = require __DIR__ . '/../../database/migrations/security/create_ln_security_audit_events_table.php';
        $migration->up();

        config()->set('ln-starter.logging.database.enabled', true);
        $this->app->forgetInstance(SecurityEventDispatcher::class);
        $this->app->forgetInstance(SecurityEventLogger::class);

        $this->app->make(SecurityEventLogger::class)->event(
            eventName: SecurityEventName::SESSION_CREATED,
            outcome: Outcome::Success,
            authMethod: 'magic_code',
            guard: 'web',
        );

        $row = DB::table(DatabaseSink::TABLE)->first();

        // Without these an operator cannot tell which application or
        // environment a row in a shared audit database came from.
        $this->assertSame(config('app.env'), $row->environment);
        $this->assertSame(config('app.name'), $row->application);
        $this->assertSame('web', $row->guard);
    }

    /**
     * Under Octane or a long-running worker a singleton context would leak the
     * correlation ID of one request into the next.
     */
    public function test_the_request_context_is_scoped_not_a_process_singleton(): void
    {
        $first = $this->app->make(RequestContext::class);
        $first->startRequest(null);
        $firstId = $first->requestId();

        $this->app->forgetScopedInstances();

        $second = $this->app->make(RequestContext::class);

        $this->assertNotSame($first, $second);
        $this->assertNull($second->requestId());

        // The singleton dispatcher must follow the new scoped instance rather
        // than keep emitting the previous request's ID.
        $second->startRequest(null);
        $this->app->make(SecurityEventLogger::class)->event('app.custom.event', Outcome::Success);

        $event = $this->find('app.custom.event');
        $this->assertSame($second->requestId(), $event->requestId);
        $this->assertNotSame($firstId, $event->requestId);
    }

    /**
     * The concurrent loser reaches the state machine through exactly this
     * branch: it takes the row lock second and finds a terminal attempt. The
     * forked race test proves it under real concurrency but cannot run
     * everywhere, so the same code path is also covered sequentially.
     */
    public function test_a_terminal_attempt_is_audited_as_a_replay_not_silently_dropped(): void
    {
        IntegrityUser::create(['email' => 'person@example.test']);
        $job = $this->requestAndProcess('person@example.test');

        $machine = $this->app->make(\LiveNetworks\LnStarter\Support\MagicLoginStateMachine::class);

        $this->assertNotNull($machine->consumeLink($job->attemptId));

        $before = count($this->sink->events);

        // Second consumption of a now-consumed attempt: this is the loser.
        $this->assertNull($machine->consumeCode($job->attemptId, 'whatever', $job->code));

        $emitted = array_slice($this->sink->events, $before);
        $replay = null;
        foreach ($emitted as $event) {
            if ($event->eventName === SecurityEventName::PROOF_REPLAYED) {
                $replay = $event;
            }
        }

        $this->assertNotNull($replay, 'A losing consumption must not vanish from the audit trail.');
        $this->assertSame(ReasonCode::ProofAlreadyConsumed, $replay->reasonCode);
        $this->assertSame($job->attemptId, $replay->attemptId);
        $this->assertSame(Outcome::Rejected, $replay->outcome);
    }

    public function test_a_revoked_attempt_is_audited_with_its_own_reason(): void
    {
        IntegrityUser::create(['email' => 'person@example.test']);
        $job = $this->requestAndProcess('person@example.test');

        DB::table('magic_login_attempts')
            ->where('id', $job->attemptId)
            ->update(['status' => 'revoked', 'revoked_at' => now()]);

        $before = count($this->sink->events);

        $this->assertNull(
            $this->app->make(\LiveNetworks\LnStarter\Support\MagicLoginStateMachine::class)
                ->consumeLink($job->attemptId)
        );

        $emitted = array_slice($this->sink->events, $before);
        $reasons = array_map(fn (SecurityEvent $e) => $e->reasonCode, $emitted);

        $this->assertContains(ReasonCode::ProofRevoked, $reasons);
    }

    public function test_a_missing_attempt_is_audited_as_not_found(): void
    {
        $before = count($this->sink->events);

        $this->assertNull(
            $this->app->make(\LiveNetworks\LnStarter\Support\MagicLoginStateMachine::class)
                ->consumeLink('01JZZZZZZZZZZZZZZZZZZZZZZZ')
        );

        $emitted = array_slice($this->sink->events, $before);
        $reasons = array_map(fn (SecurityEvent $e) => $e->reasonCode, $emitted);

        $this->assertContains(ReasonCode::AttemptNotFound, $reasons);
    }

    public function test_proof_and_confirmation_throttles_do_not_claim_to_be_session_limits(): void
    {
        IntegrityUser::create(['email' => 'person@example.test']);
        $job = $this->requestAndProcess('person@example.test');

        for ($i = 0; $i < 12; $i++) {
            $this->get('/auth/magic/' . $job->linkToken);
        }

        $throttle = $this->find(SecurityEventName::RATE_LIMITED);

        $this->assertNotNull($throttle);
        $this->assertSame(
            ReasonCode::RateLimitedProof,
            $throttle->reasonCode,
            'A per-proof limit is not a session limit.'
        );
    }

    /**
     * Drives the real deep-readiness path rather than emitting the expected
     * event by hand — the previous version of this test would have passed even
     * if AuthV2Configuration went back to the old event name.
     */
    public function test_pepper_unavailability_emits_the_documented_event_from_real_readiness(): void
    {
        $user = IntegrityUser::create(['email' => 'rotation@example.test']);
        $this->requestAndProcess('rotation@example.test');

        // The attempt is pending and unexpired, but its pepper is gone.
        DB::table('magic_login_attempts')->update(['pepper_id' => 'v-removed']);

        $secretPepper = 'base64:' . base64_encode(str_repeat('Z', 32));
        config()->set('ln-starter.auth.peppers.current', 'v1');
        config()->set('ln-starter.auth.peppers.keys', ['v1' => $secretPepper]);

        $before = count($this->sink->events);

        try {
            $this->app->make(\LiveNetworks\LnStarter\Support\AuthV2Configuration::class)->validate(true);
            $this->fail('Readiness must fail when an active attempt references a missing pepper.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('pepper is unavailable', $exception->getMessage());
            $this->assertStringNotContainsString($secretPepper, $exception->getMessage());
            $this->assertStringNotContainsString('ZZZZ', $exception->getMessage());
        }

        $emitted = array_slice($this->sink->events, $before);
        $names = array_map(fn (SecurityEvent $e) => $e->eventName, $emitted);

        $this->assertContains(SecurityEventName::READINESS_FAILED, $names);
        $this->assertNotContains('auth.magic.pepper.unavailable', $names);

        $event = null;
        foreach ($emitted as $candidate) {
            if ($candidate->eventName === SecurityEventName::READINESS_FAILED) {
                $event = $candidate;
            }
        }

        $this->assertSame(ReasonCode::PepperUnavailable, $event->reasonCode);
        $this->assertSame('v-removed', $event->context['pepper_id'] ?? null);

        $serialized = json_encode(array_map(fn (SecurityEvent $e) => $e->toArray(), $emitted));
        $this->assertStringNotContainsString($secretPepper, $serialized);
        $this->assertStringNotContainsString('ZZZZ', $serialized);
    }

    /**
     * The logger is a singleton but must never pin a scoped context: it is what
     * AuthController asks for the correlation ID it hands to the queue.
     */
    public function test_a_previously_resolved_logger_follows_the_new_scope(): void
    {
        $logger = $this->app->make(SecurityEventLogger::class);

        $first = $this->app->make(RequestContext::class);
        $first->startRequest(null);
        $firstId = $first->requestId();

        $this->assertSame($firstId, $logger->requestId());

        $this->app->forgetScopedInstances();

        $second = $this->app->make(RequestContext::class);
        $second->startRequest(null);

        $this->assertNotSame($first, $second);
        $this->assertNotSame($firstId, $second->requestId());

        // Same logger object, new scope: it must not hand back the old ID.
        $this->assertSame($second->requestId(), $logger->requestId());
        $this->assertNotSame($firstId, $logger->requestId());
        $this->assertSame($second->correlationId(), $logger->correlationId());
    }

    public function test_a_queued_job_receives_the_current_scope_correlation_id(): void
    {
        IntegrityUser::create(['email' => 'person@example.test']);

        $logger = $this->app->make(SecurityEventLogger::class);
        $this->app->make(RequestContext::class)->startRequest(null);
        $staleId = $logger->requestId();

        $this->app->forgetScopedInstances();

        $this->post('/auth/magic-link', ['email' => 'person@example.test']);

        $job = Queue::pushed(ProcessMagicLoginRequest::class)->last();

        $this->assertInstanceOf(ProcessMagicLoginRequest::class, $job);
        $this->assertNotSame(
            $staleId,
            $job->requestId,
            'The queued job inherited a correlation ID from a dead scope.'
        );

        // And it matches what the request actually emitted.
        $accepted = $this->find(SecurityEventName::REQUEST_ACCEPTED);
        $this->assertSame($accepted->correlationId, $job->requestId);
    }
}

class IntegrityUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;
}

class HostileNameSink implements SecurityAuditSink
{
    public function name(): string
    {
        throw new \RuntimeException('name() must never be called unguarded.');
    }

    public function write(SecurityEvent $event): void
    {
        throw new \RuntimeException('write also fails');
    }
}
