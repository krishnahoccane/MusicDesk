<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Music catalog: reference data (genres, languages), contributors, releases,
 * tracks and the release approval history. Artwork and audio live on the
 * private `local` disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique()->comment('ISO 639-1 (or zxx = no linguistic content)');
            $table->string('name', 80);
            $table->timestamps();
        });

        Schema::create('contributors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->index();
            $table->string('legal_name', 200)->nullable();
            $table->string('ipi_number', 11)->nullable()->comment('IPI name number, 9-11 digits');
            $table->string('pro', 60)->nullable()->comment('Performing rights organisation');
            $table->string('email')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200)->index();
            $table->string('version', 100)->nullable();
            $table->string('type', 20)->default('single');
            $table->string('status', 20)->default('draft')->index();
            $table->string('upc', 14)->nullable()->unique();
            $table->string('catalog_number', 50)->nullable()->unique();
            $table->string('label_name', 150)->nullable();
            $table->string('c_line', 200)->nullable();
            $table->string('p_line', 200)->nullable();
            $table->date('release_date')->nullable()->index();
            $table->date('original_release_date')->nullable();
            $table->foreignId('genre_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('language_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('explicit')->default(false);
            $table->string('artwork_path')->nullable();
            $table->string('artwork_thumb_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('release_artists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained()->restrictOnDelete();
            $table->string('role', 20)->default('primary');
            $table->unsignedSmallInteger('position')->default(1);

            $table->unique(['release_id', 'artist_id']);
        });

        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('disc_number')->default(1);
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('title', 200);
            $table->string('version', 100)->nullable();
            $table->string('isrc', 12)->nullable()->index();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('explicit')->default(false);
            $table->foreignId('language_id')->nullable()->constrained()->nullOnDelete();
            $table->string('audio_path')->nullable();
            $table->string('audio_original_name')->nullable();
            $table->string('audio_mime', 60)->nullable();
            $table->unsignedBigInteger('audio_size')->nullable();
            $table->unsignedInteger('audio_sample_rate')->nullable();
            $table->unsignedTinyInteger('audio_bit_depth')->nullable();
            $table->unsignedTinyInteger('audio_channels')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['release_id', 'disc_number', 'position']);
        });

        Schema::create('track_artists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained()->restrictOnDelete();
            $table->string('role', 20)->default('primary');
            $table->unsignedSmallInteger('position')->default(1);

            $table->unique(['track_id', 'artist_id']);
        });

        Schema::create('track_contributors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contributor_id')->constrained()->restrictOnDelete();
            $table->string('role', 30);
            $table->decimal('share', 7, 4)->nullable()->comment('Percentage split for this role (0-100)');

            $table->unique(['track_id', 'contributor_id', 'role']);
        });

        Schema::create('release_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_status_histories');
        Schema::dropIfExists('track_contributors');
        Schema::dropIfExists('track_artists');
        Schema::dropIfExists('tracks');
        Schema::dropIfExists('release_artists');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('contributors');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('genres');
    }
};
