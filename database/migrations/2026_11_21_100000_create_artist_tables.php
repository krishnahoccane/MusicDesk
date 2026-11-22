<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artist management: artists, their contacts and links, contracts and
 * private documents. Files live on the private `local` disk, never public/.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->index();
            $table->string('legal_name', 200)->nullable();
            $table->string('type', 20)->default('solo');
            $table->string('status', 20)->default('active')->index();
            $table->char('country', 2)->nullable();
            $table->string('primary_genre', 80)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('bio')->nullable();
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('artist_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('role', 40)->default('other');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('artist_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 30);
            $table->string('url', 500);
            $table->timestamps();

            $table->index(['artist_id', 'platform']);
        });

        Schema::create('artist_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('type', 30)->default('recording');
            $table->string('status', 20)->default('draft')->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable()->index();
            $table->decimal('artist_share', 7, 4)->nullable()->comment('Artist royalty share in percent (0-100)');
            $table->decimal('advance_amount', 18, 6)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('territory', 150)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('artist_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_contract_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->string('category', 30)->default('other');
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_documents');
        Schema::dropIfExists('artist_contracts');
        Schema::dropIfExists('artist_links');
        Schema::dropIfExists('artist_contacts');
        Schema::dropIfExists('artists');
    }
};
