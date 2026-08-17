<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable security audit trail for the opt-in database sink.
 *
 * Deliberately separate from the auth attempt tables: audit retention, access
 * control, and growth characteristics are different concerns from short-lived
 * login state.
 *
 * No column here holds a raw secret or raw personal data. `principal_key` is a
 * versioned HMAC, and `context` only ever receives sanitizer output.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ln_security_audit_events')) {
            return;
        }

        Schema::create('ln_security_audit_events', function (Blueprint $table) {
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

            // Retention pruning scans occurred_at; investigation scans the rest.
            $table->index('occurred_at');
            $table->index(['event_name', 'occurred_at']);
            $table->index('correlation_id');
            $table->index('request_id');
            $table->index('outcome');
            $table->index('principal_key');
            $table->index('attempt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ln_security_audit_events');
    }
};
