<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.theme NULL now means "not chosen", so the administrator's default
 * colour mode (Settings → Appearance) applies. "system" was the old column
 * default, so it is treated as not chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 10)->nullable()->default(null)->change();
        });

        DB::table('users')->where('theme', 'system')->update(['theme' => null]);
    }

    public function down(): void
    {
        DB::table('users')->whereNull('theme')->update(['theme' => 'system']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 10)->default('system')->change();
        });
    }
};
