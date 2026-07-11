<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_payment_allocations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('guest_payment_transaction_id', 26);
            $table->char('folio_id', 26);
            $table->decimal('amount', 12, 2);
            $table->string('allocation_idempotency_key', 96);
            $table->timestamp('allocated_at');
            $table->char('allocated_by', 26);
            $table->json('source_snapshot');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_payment_transaction_id'], 'guest_allocations_property_payment_foreign')
                ->references(['property_id', 'id'])->on('guest_payment_transactions')->restrictOnDelete();
            $table->foreign(['property_id', 'folio_id'], 'guest_allocations_property_folio_foreign')
                ->references(['property_id', 'id'])->on('folios')->restrictOnDelete();
            $table->foreign('allocated_by')->references('id')->on('users')->restrictOnDelete();

            $table->unique(['property_id', 'id'], 'guest_allocations_property_id_unique');
            $table->unique(['property_id', 'id', 'guest_payment_transaction_id'], 'guest_allocations_property_id_payment_unique');
            $table->unique(['property_id', 'allocation_idempotency_key'], 'guest_allocations_property_idem_unique');
            $table->index(['property_id', 'guest_payment_transaction_id'], 'guest_allocations_property_payment_index');
            $table->index(['property_id', 'folio_id'], 'guest_allocations_property_folio_index');
        });

        DB::statement("ALTER TABLE guest_payment_allocations ADD CONSTRAINT guest_allocations_amount_positive_check CHECK (amount > 0)");
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION block_guest_payment_allocation_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_ALLOCATIONS_IMMUTABLE';
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER guest_payment_allocations_immutable_trigger BEFORE UPDATE OR DELETE ON guest_payment_allocations FOR EACH ROW EXECUTE FUNCTION block_guest_payment_allocation_mutation()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS guest_payment_allocations_immutable_trigger ON guest_payment_allocations');
        DB::statement('DROP FUNCTION IF EXISTS block_guest_payment_allocation_mutation()');
        Schema::dropIfExists('guest_payment_allocations');
    }
};
