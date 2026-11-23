<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self sign-up: which form an account came from, and when an administrator
 * approved it (activating it the first time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_type', 10)->nullable()->after('is_active'); // artist | label
            $table->timestamp('approved_at')->nullable()->after('signup_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['signup_type', 'approved_at']);
        });
    }
};
