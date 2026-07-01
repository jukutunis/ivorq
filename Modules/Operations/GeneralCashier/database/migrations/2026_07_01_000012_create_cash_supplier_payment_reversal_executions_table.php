<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_supplier_payment_reversal_executions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('cash_return_evidence_id')->unique();
            $table->ulid('original_payment_execution_id')->unique();
            $table->ulid('original_posted_journal_entry_id')->unique();
            $table->ulid('property_id')->index();
            $table->ulid('vendor_id')->index();
            $table->ulid('operational_gl_account_id')->index();
            $table->string('currency_code', 3);
            $table->decimal('reversal_amount', 19, 2);
            $table->ulid('reversed_by')->index();
            $table->timestamp('reversed_at');
            $table->string('source_identity_hash', 64)->unique();
            $table->json('source_snapshot');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_supplier_payment_reversal_executions');
    }
};
