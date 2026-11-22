<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artist accounts (users with the "Artist" role, linked to the artist
 * profiles they may see).
 *
 * Earlier builds also created the bulk upload log (bulk_uploads) here. Bulk upload
 * is now the "bulk-upload" add-on, whose own migration creates that table when
 * missing; installations that already have it keep it and its history.
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
        Schema::dropIfExists('artist_user');
    }
};
