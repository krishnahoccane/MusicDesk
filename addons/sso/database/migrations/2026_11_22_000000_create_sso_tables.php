<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 40)->unique();           // in the callback URL; never changes
            $table->string('preset', 16);                   // entra, google, okta, oidc
            $table->string('issuer', 255);
            $table->string('client_id', 255);
            $table->text('client_secret');                  // encrypted
            $table->string('scopes', 255)->default('openid email profile');
            $table->json('allowed_domains')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('jit_enabled')->default(false);
            $table->string('jit_role', 125)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sso_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sso_provider_id')->constrained('sso_providers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject', 255);
            $table->string('email', 255)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['sso_provider_id', 'subject']);
            $table->unique(['sso_provider_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_identities');
        Schema::dropIfExists('sso_providers');
    }
};
