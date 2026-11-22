<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields returned by the MusicDesk license server (api/license/v1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_activations', function (Blueprint $table) {
            $table->string('environment', 20)->nullable()->after('domain');
            $table->string('max_version', 20)->nullable()->after('updates_until');
            $table->boolean('check_in_requested')->default(false)->after('max_version');
            $table->json('messages')->nullable()->after('check_in_requested');
            $table->boolean('offline')->default(false)->after('messages');
        });
    }

    public function down(): void
    {
        Schema::table('license_activations', function (Blueprint $table) {
            $table->dropColumn(['environment', 'max_version', 'check_in_requested', 'messages', 'offline']);
        });
    }
};
