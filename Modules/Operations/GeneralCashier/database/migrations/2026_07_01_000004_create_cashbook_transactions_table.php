<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashbook_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('operational_gl_account_id')->index();
            $table->string('currency_code', 3);
            $table->decimal('amount', 19, 2);
            $table->string('direction', 20);
            $table->date('posted_business_date')->index();
            $table->ulid('journal_entry_id')->unique();
            $table->ulid('payment_execution_id')->unique();
            $table->string('source_module', 64);
            $table->string('source_type', 64);
            $table->ulid('source_id');
            $table->string('source_event', 80);
            $table->string('source_identity_hash', 64)->unique();
            $table->json('source_snapshot');
            $table->ulid('projected_by')->nullable()->index();
            $table->timestamp('projected_at');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();

            $table->index(['property_id', 'operational_gl_account_id', 'currency_code'], 'cashbook_transactions_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashbook_transactions');
    }
};
