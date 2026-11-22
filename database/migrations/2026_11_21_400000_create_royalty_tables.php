<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Royalties. All money is DECIMAL(18,6) and handled as strings with BCMath.
 *
 *  periods → imports (statement files) → lines (one per sales row)
 *          → earnings (per artist / track / contract, rebuilt on recalculation)
 *  artist_ledger_entries: the running account per artist (earnings when a
 *  period is locked, advances, expenses, payments, adjustments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('royalty_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('royalty_import_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->json('column_map');
            $table->char('decimal_separator', 1)->default('.');
            $table->char('currency', 3)->nullable();
            $table->timestamps();
        });

        Schema::create('royalty_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('royalty_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('royalty_import_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_name', 100);
            $table->string('original_name');
            $table->string('file_path');
            $table->char('file_hash', 64)->index();
            $table->string('status', 20)->default('uploaded')->index();
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->json('column_map')->nullable();
            $table->char('decimal_separator', 1)->default('.');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('matched_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->decimal('net_total', 18, 6)->default(0)->comment('In the statement currency');
            $table->decimal('net_total_base', 18, 6)->default(0)->comment('In the label currency');
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('royalty_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('royalty_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('royalty_period_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('release_id')->nullable()->constrained()->nullOnDelete();
            $table->string('isrc', 12)->nullable()->index();
            $table->string('upc', 14)->nullable()->index();
            $table->string('store', 100)->nullable();
            $table->string('territory', 50)->nullable();
            $table->date('sale_date')->nullable();
            $table->bigInteger('units')->default(0);
            $table->decimal('net_amount', 18, 6);
            $table->decimal('net_amount_base', 18, 6);
            $table->string('match_status', 20)->default('unmatched');

            $table->index(['royalty_period_id', 'match_status']);
        });

        Schema::create('royalty_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('royalty_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained()->restrictOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('release_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('artist_contract_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('units')->default(0);
            $table->decimal('revenue', 18, 6)->comment('Artist portion of net revenue, label currency');
            $table->decimal('artist_share', 7, 4);
            $table->decimal('artist_amount', 18, 6);
            $table->decimal('label_amount', 18, 6);

            $table->index(['royalty_period_id', 'artist_id']);
        });

        Schema::create('artist_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->restrictOnDelete();
            $table->foreignId('royalty_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 20)->index();
            $table->decimal('amount', 18, 6)->comment('Positive: owed to the artist; negative: paid or recoupable');
            $table->date('entry_date')->index();
            $table->string('description', 255);
            $table->string('method', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['artist_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_ledger_entries');
        Schema::dropIfExists('royalty_earnings');
        Schema::dropIfExists('royalty_lines');
        Schema::dropIfExists('royalty_imports');
        Schema::dropIfExists('royalty_import_profiles');
        Schema::dropIfExists('royalty_periods');
    }
};
