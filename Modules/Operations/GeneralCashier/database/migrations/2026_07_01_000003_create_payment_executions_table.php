<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_executions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('vendor_id')->index();
            $table->ulid('payment_proposal_id')->index();
            $table->ulid('payment_proposal_item_id')->unique();
            $table->ulid('source_journal_entry_id')->unique();
            $table->ulid('source_journal_candidate_id')->index();
            $table->ulid('supplier_invoice_id')->index();
            $table->ulid('cashier_session_id')->index();
            $table->ulid('cashier_payment_instrument_id')->index();
            $table->ulid('operational_gl_account_id')->index();
            $table->string('currency_code', 3);
            $table->decimal('source_amount', 19, 2);
            $table->ulid('executed_by')->index();
            $table->timestamp('executed_at');
            $table->json('source_snapshot');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();

            $table->foreign('payment_proposal_id')
                ->references('id')
                ->on('payment_proposals')
                ->restrictOnDelete();

            $table->foreign('payment_proposal_item_id')
                ->references('id')
                ->on('payment_proposal_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_executions');
    }
};
