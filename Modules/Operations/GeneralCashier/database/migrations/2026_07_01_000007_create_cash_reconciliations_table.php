<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_reconciliations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('cash_reconciliation_baseline_id')->unique();
            $table->ulid('ending_cash_count_evidence_id')->unique();
            $table->ulid('property_id')->index();
            $table->ulid('operational_gl_account_id')->index();
            $table->string('currency_code', 3);
            $table->date('scope_start_exclusive_date')->index();
            $table->date('scope_end_inclusive_date')->index();
            $table->decimal('baseline_amount', 19, 2);
            $table->decimal('cashbook_inflow_amount', 19, 2);
            $table->decimal('cashbook_outflow_amount', 19, 2);
            $table->decimal('expected_amount', 19, 2);
            $table->decimal('observed_amount', 19, 2);
            $table->decimal('difference_amount', 19, 2);
            $table->string('status', 20);
            $table->ulid('reconciled_by')->index();
            $table->timestamp('reconciled_at');
            $table->string('source_identity_hash', 64)->unique();
            $table->json('source_snapshot');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();

            $table->index(
                ['property_id', 'operational_gl_account_id', 'currency_code', 'scope_start_exclusive_date', 'scope_end_inclusive_date'],
                'cash_reconciliations_scope_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_reconciliations');
    }
};
