<?php

namespace LiveNetworks\LnStarter\Tests\Auth;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LiveNetworks\LnStarter\Jobs\ProcessMagicLoginRequest;
use LiveNetworks\LnStarter\Tests\TestCase;

class EncryptedQueuePayloadTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => null,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('jobs');
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function test_database_queue_payload_encrypts_all_request_and_proof_data(): void
    {
        $email = 'queue-secret@example.test';
        $link = 'raw-link-token-that-must-not-appear';
        $code = '654321';

        ProcessMagicLoginRequest::dispatch(
            '01K00000000000000000000000',
            $email,
            $link,
            $code,
            str_repeat('n', 64),
            'v1',
            str_repeat('e', 64),
            now()->addMinutes(15)->toIso8601String(),
            'request-id',
            'en',
        );

        $payload = (string) DB::table('jobs')->value('payload');
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        foreach ([$email, $link, $code, str_repeat('n', 64), str_repeat('e', 64)] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }

        $decrypted = app('encrypter')->decrypt($decoded['data']['command']);
        $this->assertStringContainsString($email, $decrypted);
        $this->assertStringContainsString($link, $decrypted);
    }
}
