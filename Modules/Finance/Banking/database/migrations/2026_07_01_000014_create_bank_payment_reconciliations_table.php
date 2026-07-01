<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_payment_reconciliations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('controlled_bank_account_id')->index();
            $table->ulid('controlled_bank_statement_line_id')->unique();
            $table->ulid('payment_execution_id')->unique();
            $table->ulid('posted_journal_entry_id')->unique();
            $table->string('currency_code', 3);
            $table->decimal('payment_amount', 19, 2);
            $table->decimal('statement_amount', 19, 2);
            $table->decimal('difference_amount', 19, 2);
            $table->string('status', 20);
            $table->ulid('reconciled_by')->index();
            $table->timestamp('reconciled_at');
            $table->string('source_identity_hash', 64)->unique();
            $table->json('source_snapshot');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_payment_reconciliations');
    }
};
