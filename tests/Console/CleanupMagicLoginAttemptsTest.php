<?php

namespace LiveNetworks\LnStarter\Tests\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LiveNetworks\LnStarter\Models\MagicLoginAttempt;
use LiveNetworks\LnStarter\Tests\TestCase;

class CleanupMagicLoginAttemptsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('magic_login_attempts');
        Schema::create('magic_login_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->char('email_key', 64);
            $table->string('pepper_id', 64);
            $table->char('link_token_hash', 64)->unique();
            $table->char('code_hash', 64);
            $table->char('requester_nonce_hash', 64);
            $table->string('status', 16);
            $table->unsignedTinyInteger('code_attempts')->default(0);
            $table->string('consumed_via', 16)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('code_locked_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_cleanup_retains_fresh_records_and_deletes_only_old_terminal_or_expired_attempts(): void
    {
        $freshPending = $this->attempt(MagicLoginAttempt::STATUS_PENDING, now()->addHour(), now());
        $freshConsumed = $this->attempt(MagicLoginAttempt::STATUS_CONSUMED, now()->addHour(), now());
        $freshExpired = $this->attempt(MagicLoginAttempt::STATUS_PENDING, now()->subMinute(), now());
        $oldPending = $this->attempt(MagicLoginAttempt::STATUS_PENDING, now()->addHour(), now()->subHours(25));
        $oldConsumed = $this->attempt(MagicLoginAttempt::STATUS_CONSUMED, now()->addHour(), now()->subHours(25));
        $oldExpired = $this->attempt(MagicLoginAttempt::STATUS_PENDING, now()->subHours(25), now()->subHours(25));

        $this->artisan('magic-login-attempts:cleanup', ['--hours' => 24])
            ->expectsOutputToContain('Deleted 2 auth-v2 attempt(s)')
            ->assertSuccessful();

        foreach ([$freshPending, $freshConsumed, $freshExpired, $oldPending] as $retained) {
            $this->assertDatabaseHas('magic_login_attempts', ['id' => $retained]);
        }
        foreach ([$oldConsumed, $oldExpired] as $deleted) {
            $this->assertDatabaseMissing('magic_login_attempts', ['id' => $deleted]);
        }

        $this->artisan('magic-link-tokens:cleanup', ['--hours' => 24])
            ->expectsOutputToContain('Deleted 0 auth-v2 attempt(s)')
            ->assertSuccessful();
    }

    private function attempt(string $status, mixed $expiresAt, mixed $updatedAt): string
    {
        $id = (string) Str::ulid();

        DB::table('magic_login_attempts')->insert([
            'id' => $id,
            'user_id' => 1,
            'email_key' => hash('sha256', $id . 'email'),
            'pepper_id' => 'v1',
            'link_token_hash' => hash('sha256', $id . 'link'),
            'code_hash' => hash('sha256', $id . 'code'),
            'requester_nonce_hash' => hash('sha256', $id . 'nonce'),
            'status' => $status,
            'code_attempts' => 0,
            'expires_at' => $expiresAt,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);

        return $id;
    }
}
