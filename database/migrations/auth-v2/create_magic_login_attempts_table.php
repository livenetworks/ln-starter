<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('magic_login_attempts')) {
            return;
        }

        Schema::create('magic_login_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
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

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_login_attempts');
    }
};
