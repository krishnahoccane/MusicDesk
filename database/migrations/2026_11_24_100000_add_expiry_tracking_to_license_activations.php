<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When LabelDesk first learned the license had expired (used for the 10-day
 * read-only window when the server does not send the exact expiry date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_activations', function (Blueprint $table) {
            $table->timestamp('expired_detected_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('license_activations', function (Blueprint $table) {
            $table->dropColumn('expired_detected_at');
        });
    }
};
