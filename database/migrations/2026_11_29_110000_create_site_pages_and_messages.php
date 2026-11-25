<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 4.3.6: pages of the label website (about, services, pricing, contact and the label's own pages)
 * and messages sent with its contact form.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_pages')) {
            Schema::create('site_pages', function (Blueprint $table) {
                $table->id();
                $table->string('type', 20)->comment('about | services | pricing | contact | custom');
                $table->string('slug', 80)->unique();
                $table->string('title', 150);
                $table->string('menu_label', 40)->nullable();
                $table->json('content')->nullable();
                $table->string('meta_description', 160)->nullable();
                $table->boolean('is_published')->default(false);
                $table->boolean('show_in_menu')->default(true);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('site_messages')) {
            Schema::create('site_messages', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('email', 255);
                $table->string('subject', 150)->nullable();
                $table->text('message');
                $table->string('ip', 45)->nullable();
                $table->boolean('mailed')->default(false);
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_messages');
        Schema::dropIfExists('site_pages');
    }
};
