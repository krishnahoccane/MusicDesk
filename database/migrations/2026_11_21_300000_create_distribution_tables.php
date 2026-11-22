<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual distribution tracking: which stores (DSPs) each release has been
 * delivered to, its status there, and takedowns. LabelDesk does not deliver
 * files to stores itself; staff record what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dsps', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('website', 255)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable()->comment('Recommended days to deliver before release date');
            $table->string('delivery_notes', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('release_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dsp_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->date('delivered_on')->nullable();
            $table->date('live_on')->nullable();
            $table->date('taken_down_on')->nullable();
            $table->string('store_id', 100)->nullable();
            $table->string('store_url', 500)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['release_id', 'dsp_id']);
        });

        Schema::create('delivery_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_delivery_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_events');
        Schema::dropIfExists('release_deliveries');
        Schema::dropIfExists('dsps');
    }
};
