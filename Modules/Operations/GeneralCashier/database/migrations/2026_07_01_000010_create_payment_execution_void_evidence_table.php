<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_execution_void_evidence', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('payment_execution_id')->unique();
            $table->ulid('property_id')->index();
            $table->ulid('vendor_id')->index();
            $table->ulid('payment_proposal_id')->index();
            $table->ulid('payment_proposal_item_id')->index();
            $table->ulid('source_journal_entry_id')->index();
            $table->ulid('source_journal_candidate_id')->index();
            $table->ulid('supplier_invoice_id')->index();
            $table->ulid('operational_gl_account_id')->index();
            $table->string('currency_code', 3);
            $table->decimal('source_amount', 19, 2);
            $table->string('void_reason', 500);
            $table->ulid('voided_by')->index();
            $table->timestamp('voided_at');
            $table->string('source_identity_hash', 64)->unique();
            $table->json('source_snapshot');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_execution_void_evidence');
    }
};
