<?php

namespace LiveNetworks\LnStarter\Tests\Auth;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LiveNetworks\LnStarter\Contracts\AuthEligibility;
use LiveNetworks\LnStarter\Jobs\ProcessMagicLoginRequest;
use LiveNetworks\LnStarter\Mail\MagicLinkMail;
use LiveNetworks\LnStarter\Models\MagicLoginAttempt;
use LiveNetworks\LnStarter\Security\SecurityEventName;
use LiveNetworks\LnStarter\Security\Sinks\DatabaseSink;
use LiveNetworks\LnStarter\Support\AuthV2Configuration;
use LiveNetworks\LnStarter\Support\MagicLoginProofs;
use LiveNetworks\LnStarter\Support\MagicLoginStateMachine;
use LiveNetworks\LnStarter\Support\SecurityEventLogger;
use LiveNetworks\LnStarter\Tests\TestCase;
use RuntimeException;

class AuthV2FeatureTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', AuthV2User::class);
        $app['config']->set('ln-starter.auth.enabled', true);
        $app['config']->set('ln-starter.auth.user_model', AuthV2User::class);
        $app['config']->set('ln-starter.auth.home_route', 'home');
        $app['config']->set('ln-starter.auth.layout', 'test-auth-layout');
        $app['config']->set('ln-starter.auth.response_floor_ms', 0);
        $app['config']->set('ln-starter.auth.response_jitter_ms', 0);
        $app['config']->set('ln-starter.auth.peppers.current', 'v1');
        $app['config']->set('ln-starter.auth.peppers.keys', [
            'v1' => str_repeat('a', 32),
            'v2' => str_repeat('b', 32),
        ]);
        $app['config']->set('ln-starter.logging.enabled', false);
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
    }

    public function test_eligible_request_queues_encrypted_job_and_worker_creates_attempt_and_mail(): void
    {
        $user = AuthV2User::create(['email' => 'person@example.test']);

        $job = $this->requestAndProcess('person@example.test');

        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $this->assertDatabaseHas('magic_login_attempts', [
            'id' => $job->attemptId,
            'user_id' => $user->getKey(),
            'status' => MagicLoginAttempt::STATUS_PENDING,
        ]);
        $this->assertDatabaseMissing('magic_login_attempts', [
            'link_token_hash' => $job->linkToken,
        ]);
        Mail::assertSent(MagicLinkMail::class, 1);

        $serialized = MagicLoginAttempt::findOrFail($job->attemptId)->toArray();
        foreach (['email_key', 'link_token_hash', 'code_hash', 'requester_nonce_hash'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $serialized);
        }
    }

    public function test_unknown_and_eligible_requests_have_same_public_json_contract(): void
    {
        AuthV2User::create(['email' => 'known@example.test']);

        $known = $this->postJson('/auth/magic-link', ['email' => 'known@example.test']);

        $this->app['session.store']->flush();
        $unknown = $this->postJson('/auth/magic-link', ['email' => 'unknown@example.test']);

        $known->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_ineligible_worker_path_creates_no_attempt_or_mail(): void
    {
        AuthV2User::create(['email' => 'disabled@example.test']);
        $this->app->instance(AuthEligibility::class, new class implements AuthEligibility {
            public function allows(AuthenticatableContract $user): bool
            {
                return false;
            }
        });

        $job = $this->requestAndProcess('disabled@example.test');

        $this->assertDatabaseMissing('magic_login_attempts', ['id' => $job->attemptId]);
        Mail::assertNothingSent();
    }

    public function test_queue_delay_past_expiry_never_sends_an_unusable_email(): void
    {
        AuthV2User::create(['email' => 'delayed@example.test']);
        $job = new ProcessMagicLoginRequest(
            (string) Str::ulid(),
            'delayed@example.test',
            str_repeat('l', 43),
            '123456',
            str_repeat('n', 64),
            'v1',
            str_repeat('e', 64),
            now()->subSecond()->toIso8601String(),
            'request-id',
            'en',
        );

        $job->handle(
            $this->app->make(AuthEligibility::class),
            $this->app->make(MagicLoginProofs::class),
            $this->app->make(SecurityEventLogger::class),
        );

        $this->assertDatabaseCount('magic_login_attempts', 0);
        Mail::assertNothingSent();
    }

    public function test_link_flow_uses_token_free_context_and_authenticates_only_after_post(): void
    {
        $user = AuthV2User::create(['email' => 'link@example.test']);
        $job = $this->requestAndProcess($user->email);
        $sessionIdBefore = session()->getId();

        $open = $this->get('/auth/magic/' . $job->linkToken);

        $open->assertStatus(303)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control');
        $this->assertGuest('web');
        $this->assertStringNotContainsString($job->linkToken, $open->headers->get('Location'));

        $context = basename(parse_url($open->headers->get('Location'), PHP_URL_PATH));
        $this->get($open->headers->get('Location'))
            ->assertOk()
            ->assertSee('Sign in on this device');

        $this->post('/auth/magic/confirm/' . $context)
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($sessionIdBefore, session()->getId());
        $this->assertDatabaseHas('magic_login_attempts', [
            'id' => $job->attemptId,
            'status' => MagicLoginAttempt::STATUS_CONSUMED,
            'consumed_via' => 'link',
        ]);
    }

    public function test_correct_code_authenticates_originating_session_without_personal_access_token(): void
    {
        $user = AuthV2User::create(['email' => 'code@example.test']);
        $job = $this->requestAndProcess($user->email);

        $this->post('/auth/magic/code', ['code' => $job->code])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertFalse(Schema::hasTable('personal_access_tokens'));
        $this->assertDatabaseHas('magic_login_attempts', [
            'id' => $job->attemptId,
            'status' => MagicLoginAttempt::STATUS_CONSUMED,
            'consumed_via' => 'code',
            'code_attempts' => 0,
        ]);
    }

    public function test_correct_code_after_four_failures_succeeds_and_fifth_wrong_code_locks_only_code(): void
    {
        $user = AuthV2User::create(['email' => 'attempts@example.test']);
        $job = $this->requestAndProcess($user->email);
        $machine = $this->app->make(MagicLoginStateMachine::class);
        $nonce = session('ln_starter.auth.code.requester_nonce');
        $wrongCode = $job->code === '999999' ? '000000' : '999999';

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->assertNull($machine->consumeCode($job->attemptId, $nonce, $wrongCode));
        }

        $this->assertNotNull($machine->consumeCode($job->attemptId, $nonce, $job->code));
        $this->assertDatabaseHas('magic_login_attempts', [
            'id' => $job->attemptId,
            'status' => MagicLoginAttempt::STATUS_CONSUMED,
            'code_attempts' => 4,
            'code_locked_at' => null,
        ]);

        $second = $this->requestAndProcess($user->email);
        $secondNonce = session('ln_starter.auth.code.requester_nonce');
        $secondWrongCode = $second->code === '999999' ? '000000' : '999999';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $machine->consumeCode($second->attemptId, $secondNonce, $secondWrongCode);
        }

        $locked = MagicLoginAttempt::findOrFail($second->attemptId);
        $this->assertNotNull($locked->code_locked_at);
        $this->assertNotNull($machine->consumeLink($second->attemptId));
    }

    public function test_wrong_requester_nonce_does_not_increment_code_failures(): void
    {
        $user = AuthV2User::create(['email' => 'nonce@example.test']);
        $job = $this->requestAndProcess($user->email);

        $this->assertNull($this->app->make(MagicLoginStateMachine::class)
            ->consumeCode($job->attemptId, 'wrong-nonce', $job->code));

        $this->assertDatabaseHas('magic_login_attempts', [
            'id' => $job->attemptId,
            'status' => MagicLoginAttempt::STATUS_PENDING,
            'code_attempts' => 0,
        ]);
    }

    public function test_email_change_and_replay_are_rejected(): void
    {
        $user = AuthV2User::create(['email' => 'before@example.test']);
        $job = $this->requestAndProcess($user->email);
        $user->update(['email' => 'after@example.test']);

        $machine = $this->app->make(MagicLoginStateMachine::class);
        $this->assertNull($machine->consumeLink($job->attemptId));

        $user->update(['email' => 'before@example.test']);
        $this->assertNull($machine->consumeLink($job->attemptId));
        $this->assertDatabaseHas('magic_login_attempts', [
            'id' => $job->attemptId,
            'status' => MagicLoginAttempt::STATUS_REVOKED,
        ]);
    }

    public function test_link_winner_invalidates_code_and_revokes_other_pending_attempts(): void
    {
        $user = AuthV2User::create(['email' => 'winner@example.test']);
        $first = $this->requestAndProcess($user->email);
        $firstNonce = session('ln_starter.auth.code.requester_nonce');
        $second = $this->requestAndProcess($user->email);

        $machine = $this->app->make(MagicLoginStateMachine::class);
        $this->assertNotNull($machine->consumeLink($first->attemptId));
        $this->assertNull($machine->consumeCode($first->attemptId, $firstNonce, $first->code));

        $this->assertDatabaseHas('magic_login_attempts', [
            'id' => $second->attemptId,
            'status' => MagicLoginAttempt::STATUS_REVOKED,
        ]);
    }

    public function test_static_confirm_segment_is_not_captured_as_link_token(): void
    {
        $this->get('/auth/magic/confirm')->assertNotFound();
    }

    public function test_legacy_polling_is_a_credential_free_gone_tombstone(): void
    {
        $expected = [
            'ok' => false,
            'error' => 'No session',
            'upgrade_required' => true,
        ];

        $this->withSession([
            'magic_link_user_id' => 123,
            'magic_link_token_id' => 456,
        ])->getJson('/magic/status')->assertGone()->assertExactJson($expected)
            ->assertSessionMissing('magic_link_user_id')
            ->assertSessionMissing('magic_link_token_id');
        $this->postJson('/magic/status')->assertGone()->assertExactJson($expected);
    }

    public function test_legacy_secret_bearing_route_names_are_not_registered(): void
    {
        $this->assertFalse(Route::has('auth.magic.show'));
        $this->assertFalse(Route::has('auth.magic.consume'));
    }

    public function test_confirmation_contexts_are_bounded_and_do_not_overwrite_each_other(): void
    {
        $contexts = [];

        for ($index = 0; $index < 4; $index++) {
            $user = AuthV2User::create(['email' => "context{$index}@example.test"]);
            $job = $this->requestAndProcess($user->email);
            $response = $this->get('/auth/magic/' . $job->linkToken);
            $contexts[] = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        }

        $stored = session('ln_starter.auth.confirmations');
        $this->assertCount(3, $stored);
        $this->assertArrayNotHasKey($contexts[0], $stored);
        $this->assertArrayHasKey($contexts[1], $stored);
        $this->assertArrayHasKey($contexts[2], $stored);
        $this->assertArrayHasKey($contexts[3], $stored);
    }

    public function test_production_readiness_fails_if_active_attempt_references_removed_pepper(): void
    {
        $user = AuthV2User::create(['email' => 'rotation@example.test']);
        $this->requestAndProcess($user->email);

        $this->app['env'] = 'production';
        config()->set('queue.default', 'database');
        config()->set('session.driver', 'file');
        config()->set('cache.default', 'database');
        config()->set('ln-starter.auth.peppers.current', 'v2');
        config()->set('ln-starter.auth.peppers.keys', [
            'v2' => str_repeat('b', 32),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('active attempt whose pepper is unavailable');

        $this->app->make(AuthV2Configuration::class)->validate(true);
    }

    public function test_production_readiness_rejects_process_local_session_lock_cache(): void
    {
        $this->app['env'] = 'production';
        config()->set('queue.default', 'database');
        config()->set('session.driver', 'file');
        config()->set('cache.default', 'array');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('shared lock-capable session block cache');

        $this->app->make(AuthV2Configuration::class)->validate();
    }

    public function test_production_readiness_rejects_a_database_without_transactional_row_locking(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Asserts the SQLite rejection path.');
        }

        $this->app['env'] = 'production';
        config()->set('queue.default', 'database');
        config()->set('session.driver', 'file');
        config()->set('cache.default', 'database');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transactional row locking');

        $this->app->make(AuthV2Configuration::class)->validate();
    }

    public function test_concurrent_link_and_code_consumption_has_exactly_one_winner(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true) || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires a row-locking database (MySQL/MariaDB/PostgreSQL) and pcntl for a real row-lock race.');
        }

        $user = AuthV2User::create(['email' => 'race@example.test']);
        $job = $this->requestAndProcess($user->email);
        $nonce = session('ln_starter.auth.code.requester_nonce');

        // Audit through the shared database so the two forked processes write
        // to one place. Asserting the winner in-process only proves one caller
        // got a user back; this proves the audit trail also records exactly one
        // acceptance, which is what an operator would actually rely on.
        $this->createAuditTableForRace();
        config()->set('ln-starter.logging.enabled', true);
        config()->set('ln-starter.logging.database.enabled', true);
        $this->app->forgetInstance(\LiveNetworks\LnStarter\Security\SecurityEventDispatcher::class);
        $this->app->forgetInstance(SecurityEventLogger::class);
        $this->app->forgetInstance(MagicLoginStateMachine::class);
        $this->app->make(\LiveNetworks\LnStarter\Security\SecurityEventDispatcher::class);

        $prefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ln-starter-race-' . bin2hex(random_bytes(8));
        $gate = $prefix . '.start';
        $results = [$prefix . '.link', $prefix . '.code'];
        $ready = [$prefix . '.ready.0', $prefix . '.ready.1'];
        $children = [];

        foreach (['link', 'code'] as $index => $method) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork row-lock race-test worker.');
            }

            if ($pid === 0) {
                DB::purge();
                DB::reconnect();

                // Warm everything that would otherwise be lazily built inside
                // the timed section, so the race is between the two locking
                // transactions and not between two container boots.
                $machine = app(MagicLoginStateMachine::class);
                app(\LiveNetworks\LnStarter\Security\SecurityEventDispatcher::class);
                DB::table(DatabaseSink::TABLE)->count();

                file_put_contents($ready[$index], '1');

                $deadline = microtime(true) + 10;
                while (!file_exists($gate) && microtime(true) < $deadline) {
                    usleep(200);
                }

                $winner = $method === 'link'
                    ? $machine->consumeLink($job->attemptId)
                    : $machine->consumeCode($job->attemptId, $nonce, $job->code);

                file_put_contents($results[$index], $winner ? '1' : '0');
                exit(0);
            }

            $children[] = $pid;
        }

        // Open the gate only once BOTH workers are connected and warm.
        // Without this barrier the first child routinely finishes before the
        // second one has a connection, and the test silently stops being a
        // race at all.
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            if (file_exists($ready[0]) && file_exists($ready[1])) {
                break;
            }
            usleep(500);
        }

        $this->assertFileExists($ready[0], 'Race worker 0 never became ready.');
        $this->assertFileExists($ready[1], 'Race worker 1 never became ready.');

        file_put_contents($gate, 'go');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $wins = array_sum(array_map(
            static fn (string $path): int => (int) file_get_contents($path),
            $results
        ));

        foreach ([$gate, ...$results, ...$ready] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        DB::purge();
        DB::reconnect();

        $this->assertSame(1, $wins);
        $this->assertDatabaseHas('magic_login_attempts', [
            'id' => $job->attemptId,
            'status' => MagicLoginAttempt::STATUS_CONSUMED,
        ]);

        $accepted = DB::table(DatabaseSink::TABLE)
            ->where('attempt_id', $job->attemptId)
            ->where('event_name', SecurityEventName::PROOF_ACCEPTED)
            ->count();

        $this->assertSame(1, $accepted, 'A concurrent race must record exactly one accepted proof.');

        // The loser must be recorded, not silently dropped: a race that leaves
        // only a success event is indistinguishable from an uncontested login.
        $rejections = DB::table(DatabaseSink::TABLE)
            ->where('attempt_id', $job->attemptId)
            ->whereIn('event_name', [
                SecurityEventName::PROOF_REJECTED,
                SecurityEventName::PROOF_REPLAYED,
            ])
            ->count();

        $this->assertGreaterThanOrEqual(
            1,
            $rejections,
            'The losing side of the race must appear in the audit trail.'
        );

        // Both sides recorded against the same attempt, in the shared sink.
        $this->assertSame(
            1,
            DB::table(DatabaseSink::TABLE)
                ->where('attempt_id', $job->attemptId)
                ->where('event_name', SecurityEventName::PROOF_ACCEPTED)
                ->where('outcome', 'success')
                ->count()
        );

        Schema::dropIfExists(DatabaseSink::TABLE);
    }

    private function createAuditTableForRace(): void
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
            $table->string('environment', 32)->nullable();
            $table->string('application', 96)->nullable();
            $table->string('guard', 32)->nullable();
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

    private function requestAndProcess(string $email): ProcessMagicLoginRequest
    {
        $this->post('/auth/magic-link', ['email' => $email])
            ->assertRedirect(route('auth.magic.code.form'));

        $job = Queue::pushed(ProcessMagicLoginRequest::class)
            ->last(fn ($queued) => $queued->canonicalEmail === strtolower($email));

        $this->assertInstanceOf(ProcessMagicLoginRequest::class, $job);
        $job->handle(
            $this->app->make(AuthEligibility::class),
            $this->app->make(MagicLoginProofs::class),
            $this->app->make(SecurityEventLogger::class),
        );

        return $job;
    }
}

class AuthV2User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
