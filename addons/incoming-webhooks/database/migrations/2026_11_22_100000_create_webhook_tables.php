<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incoming webhooks add-on: endpoints, the delivery log and the
 * idempotency ledger (one row per accepted event id per endpoint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('token', 64)->unique()->comment('Unguessable part of the URL /api/hooks/{token}');
            $table->string('action', 40);
            $table->text('secret')->comment('Encrypted signing secret');
            $table->text('previous_secret')->nullable()->comment('Encrypted; still accepted until previous_secret_expires_at');
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->unsignedSmallInteger('tolerance_seconds')->default(300);
            $table->text('allowed_ips')->nullable();
            $table->text('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event_id', 191)->nullable()->index();
            $table->string('status', 20)->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->text('headers')->nullable();
            $table->longText('payload')->nullable();
            $table->unsignedInteger('payload_size')->default(0);
            $table->boolean('payload_truncated')->default(false);
            $table->text('result')->nullable();
            $table->string('error', 1000)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('replay_of_id')->nullable()->constrained('webhook_deliveries')->nullOnDelete();
            $table->foreignId('replayed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'created_at']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event_id', 191);
            $table->foreignId('webhook_delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();

            $table->unique(['webhook_endpoint_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
