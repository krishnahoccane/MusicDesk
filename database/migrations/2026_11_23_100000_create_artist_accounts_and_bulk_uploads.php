<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artist accounts (users with the "Artist" role, linked to the artist
 * profiles they may see) and the bulk upload log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['artist_id', 'user_id']);
        });

        Schema::create('bulk_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->index()->comment('artists | releases');
            $table->string('original_name');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamps();
        });

        Schema::table('royalty_lines', function (Blueprint $table) {
            // Report groupings (Milestone 10 performance review).
            $table->index(['royalty_period_id', 'store']);
            $table->index(['royalty_period_id', 'territory']);
        });
    }

    public function down(): void
    {
        Schema::table('royalty_lines', function (Blueprint $table) {
            $table->dropIndex(['royalty_period_id', 'store']);
            $table->dropIndex(['royalty_period_id', 'territory']);
        });
        Schema::dropIfExists('bulk_uploads');
        Schema::dropIfExists('artist_user');
    }
};
