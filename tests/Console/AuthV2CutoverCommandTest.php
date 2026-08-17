<?php

namespace LiveNetworks\LnStarter\Tests\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LiveNetworks\LnStarter\Tests\TestCase;

class AuthV2CutoverCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('magic_link_tokens');
        Schema::create('magic_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->boolean('approved')->default(false);
        });

        DB::table('magic_link_tokens')->insert([
            ['approved' => false],
            ['approved' => true],
        ]);
    }

    public function test_cutover_requires_explicit_force_and_invalidates_pending_v1_proofs(): void
    {
        $this->artisan('ln-starter:auth-v2-cutover')->assertFailed();
        $this->assertSame(2, DB::table('magic_link_tokens')->count());

        $this->artisan('ln-starter:auth-v2-cutover', ['--force' => true])
            ->expectsOutputToContain('Invalidated 1 pending auth-v1 proof(s)')
            ->assertSuccessful();

        $this->assertDatabaseMissing('magic_link_tokens', ['approved' => false]);
        $this->assertDatabaseHas('magic_link_tokens', ['approved' => true]);
    }
}
