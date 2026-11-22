<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugins', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 120);
            $table->string('version', 20);
            $table->string('addon', 40);                 // add-on code its license key must be issued for
            $table->text('description')->nullable();
            $table->json('manifest');
            $table->boolean('active')->default(false);
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('last_error', 500)->nullable();
            // Add-on license (its own key from MusicDesk.tech, verified there; token signed by MusicDesk.tech)
            $table->text('license_key')->nullable();     // encrypted
            $table->text('license_token')->nullable();
            $table->string('license_status', 20)->nullable();
            $table->timestamp('license_expires_at')->nullable();
            $table->timestamp('license_checked_at')->nullable();
            $table->string('license_error', 500)->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->insertOrIgnore([
                'name' => 'plugins.manage', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plugins');
    }
};
