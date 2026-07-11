<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_payment_reversals', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('guest_payment_transaction_id', 26);
            $table->char('guest_payment_allocation_id', 26)->nullable();
            $table->string('reversal_type', 32);
            $table->decimal('amount', 12, 2);
            $table->string('reason_code', 80);
            $table->string('reversal_idempotency_key', 96);
            $table->timestamp('reversed_at');
            $table->char('reversed_by', 26);
            $table->json('source_snapshot');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_payment_transaction_id'], 'guest_reversals_property_payment_foreign')
                ->references(['property_id', 'id'])->on('guest_payment_transactions')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_payment_allocation_id'], 'guest_reversals_property_allocation_foreign')
                ->references(['property_id', 'id'])->on('guest_payment_allocations')->restrictOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->restrictOnDelete();

            $table->unique(['property_id', 'id'], 'guest_reversals_property_id_unique');
            $table->unique(['property_id', 'reversal_idempotency_key'], 'guest_reversals_property_idem_unique');
            $table->index(['property_id', 'guest_payment_transaction_id'], 'guest_reversals_property_payment_index');
        });

        DB::statement("ALTER TABLE guest_payment_reversals ADD CONSTRAINT guest_reversals_amount_positive_check CHECK (amount > 0)");
        DB::statement("ALTER TABLE guest_payment_reversals ADD CONSTRAINT guest_reversals_type_check CHECK (reversal_type IN ('PAYMENT_VOID','ALLOCATION_REVERSAL'))");
        DB::statement("ALTER TABLE guest_payment_reversals ADD CONSTRAINT guest_reversals_reference_check CHECK ((reversal_type = 'PAYMENT_VOID' AND guest_payment_allocation_id IS NULL) OR (reversal_type = 'ALLOCATION_REVERSAL' AND guest_payment_allocation_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX guest_reversals_one_void_per_payment_unique ON guest_payment_reversals (property_id, guest_payment_transaction_id) WHERE reversal_type = 'PAYMENT_VOID'");
        DB::statement("CREATE UNIQUE INDEX guest_reversals_one_reversal_per_allocation_unique ON guest_payment_reversals (property_id, guest_payment_allocation_id) WHERE reversal_type = 'ALLOCATION_REVERSAL'");
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_payment_reversals');
    }
};
