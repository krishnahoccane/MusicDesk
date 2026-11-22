<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_activations', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('sales_channel', 30)->default('musicdesk_direct');
            $table->text('license_key');                   // encrypted with APP_KEY
            $table->char('license_key_hash', 64)->index(); // sha256 for lookups, never the raw key
            $table->string('activation_id', 100)->nullable();
            $table->text('activation_secret')->nullable(); // encrypted
            $table->string('domain');
            $table->string('application_url');
            $table->char('installation_fingerprint', 64);
            $table->string('status', 20)->default('active')->index(); // active|deactivated|transferred
            $table->string('license_status', 20)->nullable();         // remote status: active|expired|...
            $table->string('plan', 50)->nullable();
            $table->string('license_type', 30)->nullable();
            $table->json('features')->nullable();
            $table->unsignedInteger('activation_limit')->nullable();
            $table->unsignedInteger('activations_used')->nullable();
            $table->unsignedInteger('user_limit')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('support_until')->nullable();
            $table->timestamp('updates_until')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_error_code', 50)->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('license_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_activation_id')->constrained()->cascadeOnDelete();
            $table->longText('payload');   // base64 signed payload
            $table->string('signature', 255);
            $table->timestamp('issued_at');
            $table->timestamp('valid_until')->index();
            $table->timestamps();
        });

        Schema::create('license_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_activation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 40)->index();   // activated|verified|verify_failed|deactivated|transferred|heartbeat
            $table->string('result', 20);           // success|failure
            $table->string('code', 50)->nullable();
            $table->string('message', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_events');
        Schema::dropIfExists('license_cache');
        Schema::dropIfExists('license_activations');
    }
};
