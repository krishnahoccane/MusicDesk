<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plugins') && ! Schema::hasColumn('plugins', 'auto_update')) {
            Schema::table('plugins', function (Blueprint $table) {
                $table->boolean('auto_update')->default(false)->after('active');
            });
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->insertOrIgnore([
                'name' => 'updates.manage', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plugins', 'auto_update')) {
            Schema::table('plugins', fn (Blueprint $table) => $table->dropColumn('auto_update'));
        }
    }
};
