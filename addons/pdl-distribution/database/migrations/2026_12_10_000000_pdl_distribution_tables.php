<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PDL deliveries (one per release: the album at COSMOS) and their history.
 * Each table is only created when missing; nothing is ever dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pdl_deliveries')) {
            Schema::create('pdl_deliveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('release_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('action', 10)->default('new')->comment('new | update');
                $table->string('stage', 12)->default('queued')->index()
                    ->comment('queued | metadata | uploading | verifying | submitting | done | updating');
                $table->string('status', 20)->default('queued')->index()
                    ->comment('queued | sending | failed | ingested | submitted | in_review | issue_reported | approved | released | live | release_error | taken_down');
                $table->string('platforms', 30)->default('AllPlatformWithYouTube');
                $table->boolean('allow_any_go_live_date')->default(false);
                $table->string('mood', 40)->nullable();
                $table->string('pdl_album_id', 40)->nullable()->index();
                $table->string('pdl_upc', 20)->nullable();
                $table->text('ingest')->nullable()->comment('encrypted JSON: ingestion token, presigned URLs, upload progress');
                $table->text('payload')->nullable()->comment('JSON album payload last sent');
                $table->json('pdl_state')->nullable()->comment('submitted / verified / reportissue flags and per-platform release status from the last sync');
                $table->text('issue')->nullable()->comment('Issue reported by the PDL team');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable()->index();
                $table->text('last_error')->nullable();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('live_at')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pdl_delivery_events')) {
            Schema::create('pdl_delivery_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pdl_delivery_id')->constrained()->cascadeOnDelete();
                $table->string('level', 10)->default('info')->comment('info | success | warning | danger');
                $table->string('message', 1000);
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Kept: the tables record what was sent to PDL.
    }
};
