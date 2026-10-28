<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_return_evidence', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('payment_execution_id')->unique();
            $table->ulid('posted_journal_entry_id')->unique();
            $table->ulid('controlled_bank_account_id')->index();
            $table->ulid('return_statement_line_id')->unique();
            $table->ulid('property_id')->index();
            $table->ulid('vendor_id')->index();
            $table->ulid('operational_gl_account_id')->index();
            $table->string('currency_code', 3);
            $table->decimal('return_amount', 19, 2);
            $table->date('observed_return_date')->index();
            $table->string('reason_code', 64);
            $table->ulid('recorded_by')->index();
            $table->timestamp('recorded_at');
            $table->string('idempotency_key', 96);
            $table->string('source_identity_hash', 64)->unique();
            $table->json('source_snapshot');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();
            $table->unique(['property_id', 'idempotency_key'], 'bank_return_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_return_evidence');
    }
};
