<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the artwork exactly as uploaded. (Until 4.3.4 this also created the cloud
 * media tables; they belong to the Media storage add-on now.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            // The artwork exactly as uploaded (artwork_path is the re-encoded display copy).
            $table->string('artwork_original_path')->nullable()->after('artwork_thumb_path');
        });

        // The cloud media tables moved to the Media storage add-on (LabelDesk 4.3.4), which creates
        // them when missing. Installations that ran this migration before keep their tables and data.
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn('artwork_original_path');
        });
    }
};
