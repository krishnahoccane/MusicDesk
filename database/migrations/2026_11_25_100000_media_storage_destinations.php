<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Until 4.3.4 this created the cloud media storage tables (several destinations).
 * They belong to the Media storage add-on now, which creates them when missing.
 * Installations that ran this before keep their tables and data: nothing is dropped.
 */
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
