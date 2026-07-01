<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_reconciliation_baselines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('cash_count_evidence_id')->unique();
            $table->ulid('property_id')->index();
            $table->ulid('operational_gl_account_id')->index();
            $table->string('currency_code', 3);
            $table->decimal('baseline_amount', 19, 2);
            $table->date('cashbook_boundary_posted_business_date')->index();
            $table->ulid('baseline_by')->index();
            $table->timestamp('baseline_at');
            $table->string('source_identity_hash', 64)->unique();
            $table->json('source_snapshot');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['property_id', 'operational_gl_account_id', 'currency_code', 'cashbook_boundary_posted_business_date'],
                'cash_reconciliation_baselines_scope_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_reconciliation_baselines');
    }
};
