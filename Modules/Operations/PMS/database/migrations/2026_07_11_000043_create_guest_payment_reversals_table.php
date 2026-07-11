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
            $table->foreign(
                ['property_id', 'guest_payment_allocation_id', 'guest_payment_transaction_id'],
                'guest_reversals_property_allocation_payment_foreign'
            )->references(['property_id', 'id', 'guest_payment_transaction_id'])->on('guest_payment_allocations')->restrictOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->restrictOnDelete();

            $table->unique(['property_id', 'id'], 'guest_reversals_property_id_unique');
            $table->unique(['property_id', 'id', 'guest_payment_allocation_id'], 'guest_reversals_property_id_allocation_unique');
            $table->unique(['property_id', 'reversal_idempotency_key'], 'guest_reversals_property_idem_unique');
            $table->index(['property_id', 'guest_payment_transaction_id'], 'guest_reversals_property_payment_index');
        });

        DB::statement("ALTER TABLE guest_payment_reversals ADD CONSTRAINT guest_reversals_amount_positive_check CHECK (amount > 0)");
        DB::statement("ALTER TABLE guest_payment_reversals ADD CONSTRAINT guest_reversals_type_check CHECK (reversal_type IN ('PAYMENT_VOID','ALLOCATION_REVERSAL'))");
        DB::statement("ALTER TABLE guest_payment_reversals ADD CONSTRAINT guest_reversals_reference_check CHECK ((reversal_type = 'PAYMENT_VOID' AND guest_payment_allocation_id IS NULL) OR (reversal_type = 'ALLOCATION_REVERSAL' AND guest_payment_allocation_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX guest_reversals_one_void_per_payment_unique ON guest_payment_reversals (property_id, guest_payment_transaction_id) WHERE reversal_type = 'PAYMENT_VOID'");
        DB::statement("CREATE UNIQUE INDEX guest_reversals_one_reversal_per_allocation_unique ON guest_payment_reversals (property_id, guest_payment_allocation_id) WHERE reversal_type = 'ALLOCATION_REVERSAL'");

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION glf_b_check_reversal_source_amount()
RETURNS trigger AS $$
DECLARE
    payment_row    RECORD;
    allocation_row RECORD;
BEGIN
    IF NEW.reversal_type = 'PAYMENT_VOID' THEN
        SELECT amount, property_id, payment_number
          INTO payment_row
          FROM guest_payment_transactions
         WHERE property_id = NEW.property_id
           AND id = NEW.guest_payment_transaction_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'GLF_B_PAYMENT_VOID_TARGET_NOT_FOUND';
        END IF;

        IF NEW.amount <> payment_row.amount THEN
            RAISE EXCEPTION 'GLF_B_REVERSAL_SOURCE_AMOUNT_MISMATCH';
        END IF;

    ELSIF NEW.reversal_type = 'ALLOCATION_REVERSAL' THEN
        SELECT a.amount, a.property_id, a.guest_payment_transaction_id
          INTO allocation_row
          FROM guest_payment_allocations a
         WHERE a.property_id = NEW.property_id
           AND a.id = NEW.guest_payment_allocation_id
           AND a.guest_payment_transaction_id = NEW.guest_payment_transaction_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'GLF_B_ALLOCATION_REVERSAL_TARGET_NOT_FOUND';
        END IF;

        IF NEW.amount <> allocation_row.amount THEN
            RAISE EXCEPTION 'GLF_B_REVERSAL_SOURCE_AMOUNT_MISMATCH';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER glf_b_reversal_source_amount_trigger BEFORE INSERT ON guest_payment_reversals FOR EACH ROW EXECUTE FUNCTION glf_b_check_reversal_source_amount()');

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION block_guest_payment_reversal_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_REVERSALS_IMMUTABLE';
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER guest_payment_reversals_immutable_trigger BEFORE UPDATE OR DELETE ON guest_payment_reversals FOR EACH ROW EXECUTE FUNCTION block_guest_payment_reversal_mutation()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS guest_payment_reversals_immutable_trigger ON guest_payment_reversals');
        DB::statement('DROP FUNCTION IF EXISTS block_guest_payment_reversal_mutation()');
        DB::statement('DROP TRIGGER IF EXISTS glf_b_reversal_source_amount_trigger ON guest_payment_reversals');
        DB::statement('DROP FUNCTION IF EXISTS glf_b_check_reversal_source_amount()');
        Schema::dropIfExists('guest_payment_reversals');
    }
};
