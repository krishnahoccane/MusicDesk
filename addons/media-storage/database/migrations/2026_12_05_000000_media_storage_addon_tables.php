<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media storage destinations, release folders and uploaded files. Installations
 * that had media storage in LabelDesk core (before 4.3.4) already have these
 * tables with their data (core migrations 2026_11_24_110000 and
 * 2026_11_25_100000), so each table is only created when missing.
 * releases.artwork_original_path stays a core column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_storage_targets')) {
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
        }

        if (! Schema::hasTable('media_storage_folders')) {
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
        }

        if (! Schema::hasTable('media_storage_files')) {
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
        }
    }

    public function down(): void
    {
        // Kept: the tables may predate the add-on, and files whose server copy
        // was removed are only reachable through these records.
    }
};
