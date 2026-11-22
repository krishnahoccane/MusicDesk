<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk upload history. Installations that had bulk upload in LabelDesk core
 * already have this table (core migration 2026_11_23_100000), so it is only
 * created when missing and existing history is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bulk_uploads')) {
            return;
        }

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
    }

    public function down(): void
    {
        // Upload history is kept: the table may predate the add-on.
    }
};
