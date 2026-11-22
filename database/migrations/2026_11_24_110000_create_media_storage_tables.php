<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cloud media storage (Google Drive / OneDrive): one folder per release and
 * one record per uploaded file (master audio, original artwork).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            // The artwork exactly as uploaded (artwork_path is the re-encoded display copy).
            $table->string('artwork_original_path')->nullable()->after('artwork_thumb_path');
        });

        Schema::create('media_storage_folders', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->string('remote_id', 200);
            $table->string('remote_parent_id', 200);
            $table->string('name', 255);
            $table->timestamps();

            $table->unique(['provider', 'release_id']);
        });

        Schema::create('media_storage_files', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('kind', 10)->comment('audio | artwork');
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('pending')->index()->comment('pending | waiting | uploaded | failed');
            $table->string('local_path')->nullable()->comment('local file the remote copy was made from');
            $table->string('remote_id', 200)->nullable();
            $table->string('remote_folder_id', 200)->nullable();
            $table->string('remote_name', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->char('md5', 32)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('delete_local_at')->nullable()->index();
            $table->timestamp('local_deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'kind', 'release_id', 'track_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_storage_files');
        Schema::dropIfExists('media_storage_folders');
        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn('artwork_original_path');
        });
    }
};
