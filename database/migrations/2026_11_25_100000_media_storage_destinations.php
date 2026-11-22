<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Media storage with several destinations (e.g. two Google Drives and an FTP
 * server). Files and folders are tracked per destination.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_storage_targets', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 30)->comment('google_drive | onedrive | onedrive_business | dropbox | zoho_workdrive | ftp');
            $table->string('name', 80);
            $table->boolean('enabled')->default(false);
            $table->text('credentials')->nullable()->comment('encrypted JSON');
            $table->string('account', 255)->nullable();
            $table->string('folder_id', 500)->nullable();
            $table->string('folder_name', 500)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });

        // Single-destination tables from the previous version (no production data yet).
        Schema::dropIfExists('media_storage_files');
        Schema::dropIfExists('media_storage_folders');

        Schema::create('media_storage_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained('media_storage_targets')->cascadeOnDelete();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->string('remote_id', 500);
            $table->string('remote_parent_id', 500);
            $table->string('name', 255);
            $table->timestamps();

            $table->unique(['target_id', 'release_id']);
        });

        Schema::create('media_storage_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained('media_storage_targets')->cascadeOnDelete();
            $table->string('kind', 10)->comment('audio | artwork');
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('pending')->index()->comment('pending | waiting | uploaded | failed');
            $table->string('local_path')->nullable();
            $table->string('remote_id', 500)->nullable();
            $table->string('remote_folder_id', 500)->nullable();
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

            $table->unique(['target_id', 'kind', 'release_id', 'track_id']);
        });

        // Old single-destination settings are replaced by destinations.
        DB::table('settings')->where('group', 'media')->whereNotIn('key', ['delete_local', 'delete_after_days'])->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('media_storage_files');
        Schema::dropIfExists('media_storage_folders');
        Schema::dropIfExists('media_storage_targets');
    }
};
