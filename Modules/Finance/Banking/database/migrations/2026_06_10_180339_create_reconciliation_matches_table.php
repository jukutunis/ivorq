<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_matches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('reconciliation_session_id')->index();
            $table->ulid('bank_statement_line_id')->unique(); // BR-007
            
            $table->ulidMorphs('matchable');
            
            $table->decimal('amount_matched', 15, 2);
            $table->boolean('is_locked')->default(false);
            
            // Snapshots
            $table->string('matchable_reference')->nullable();
            $table->decimal('matchable_amount', 15, 2);
            $table->string('statement_reference')->nullable();
            $table->decimal('statement_amount', 15, 2);
            $table->decimal('bank_account_balance_before', 15, 2);
            $table->decimal('bank_account_balance_after', 15, 2);
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            
            // BR-008 Unique matchable (e.g. PaymentVoucher can only be reconciled once)
            $table->unique(['matchable_type', 'matchable_id'], 'unique_reconciled_matchable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_matches');
    }
};
