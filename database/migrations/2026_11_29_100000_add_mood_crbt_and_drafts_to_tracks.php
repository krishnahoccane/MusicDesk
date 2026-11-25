<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 4.3.6: track mood (from the PDL COSMOS mood list, stored with PDL's exact spelling),
 * caller ring-back tone (CRBT) cut, and tracks saved as drafts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('moods')) {
            Schema::create('moods', function (Blueprint $table) {
                $table->id();
                $table->string('name', 60)->comment('shown in LabelDesk');
                $table->string('value', 60)->unique()->comment('exact value distributors such as PDL expect');
                $table->unsignedSmallInteger('sort')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        Schema::table('tracks', function (Blueprint $table) {
            if (! Schema::hasColumn('tracks', 'mood_id')) {
                $table->foreignId('mood_id')->nullable()->after('language_id')->constrained('moods')->nullOnDelete();
            }
            if (! Schema::hasColumn('tracks', 'crbt_name')) {
                $table->string('crbt_name', 64)->nullable()->after('mood_id');
                $table->unsignedSmallInteger('crbt_start_seconds')->nullable()->after('crbt_name');
            }
            if (! Schema::hasColumn('tracks', 'is_draft')) {
                $table->boolean('is_draft')->default(false)->after('crbt_start_seconds');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mood_id');
            $table->dropColumn(['crbt_name', 'crbt_start_seconds', 'is_draft']);
        });
        Schema::dropIfExists('moods');
    }
};
