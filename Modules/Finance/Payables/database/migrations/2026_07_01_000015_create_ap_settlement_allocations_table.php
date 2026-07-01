<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ap_settlement_allocations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('vendor_id')->index();
            $table->string('currency_code', 3);
            $table->ulid('ap_journal_entry_id')->index();
            $table->ulid('payment_journal_entry_id')->unique();
            $table->ulid('payment_execution_id')->unique();
            $table->decimal('allocation_amount', 19, 2);
            $table->ulid('allocated_by')->index();
            $table->timestamp('allocated_at');
            $table->string('source_identity_hash', 64)->unique();
            $table->json('source_snapshot');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['ap_journal_entry_id', 'payment_journal_entry_id'],
                'ap_settlement_allocations_ap_payment_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_settlement_allocations');
    }
};
