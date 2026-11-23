<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-labels (imprints) under the main label, and the label a release belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('labels')->nullOnDelete(); // null = directly under the main label
            $table->string('name', 150);
            $table->string('code', 20)->nullable()->unique();      // catalogue-number prefix
            $table->string('contact_name', 150)->nullable();
            $table->string('email')->nullable();
            $table->string('website', 255)->nullable();
            $table->char('country', 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // account that signed up for it
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'name']);
        });

        Schema::table('releases', function (Blueprint $table) {
            $table->foreignId('label_id')->nullable()->after('label_name')->constrained('labels')->nullOnDelete();
        });

    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('label_id');
        });
        Schema::dropIfExists('labels');
    }
};
