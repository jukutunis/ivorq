<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unique(['property_id', 'id'], 'reservations_property_id_unique');
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->unique(['property_id', 'id'], 'guests_property_id_unique');
        });

        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->unique(['property_id', 'id'], 'cashier_sessions_property_id_unique');
        });

        Schema::create('guest_payment_transactions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('payment_number', 32);
            $table->char('reservation_id', 26);
            $table->char('guest_id', 26);
            $table->string('currency', 3);
            $table->decimal('amount', 12, 2);
            $table->string('tender_type', 20);
            $table->char('cashier_session_id', 26);
            $table->string('lifecycle_status', 32);
            $table->string('recording_idempotency_key', 96);
            $table->timestamp('recorded_at');
            $table->char('recorded_by', 26);
            $table->json('source_snapshot');
            $table->timestamps();
            $table->char('created_by', 26);
            $table->char('updated_by', 26)->nullable();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign(['property_id', 'reservation_id'], 'guest_payments_property_reservation_foreign')
                ->references(['property_id', 'id'])->on('reservations')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_id'], 'guest_payments_property_guest_foreign')
                ->references(['property_id', 'id'])->on('guests')->restrictOnDelete();
            $table->foreign(['property_id', 'cashier_session_id'], 'guest_payments_property_session_foreign')
                ->references(['property_id', 'id'])->on('cashier_sessions')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'id'], 'guest_payments_property_id_unique');
            $table->unique(['property_id', 'payment_number'], 'guest_payments_property_number_unique');
            $table->unique(['property_id', 'recording_idempotency_key'], 'guest_payments_property_idem_unique');
            $table->index(['property_id', 'reservation_id'], 'guest_payments_property_reservation_index');
            $table->index(['property_id', 'cashier_session_id'], 'guest_payments_property_session_index');
        });

        DB::statement("ALTER TABLE guest_payment_transactions ADD CONSTRAINT guest_payments_amount_positive_check CHECK (amount > 0)");
        DB::statement("ALTER TABLE guest_payment_transactions ADD CONSTRAINT guest_payments_tender_check CHECK (tender_type IN ('CASH'))");
        DB::statement("ALTER TABLE guest_payment_transactions ADD CONSTRAINT guest_payments_status_check CHECK (lifecycle_status IN ('RECORDED','PARTIALLY_ALLOCATED','FULLY_ALLOCATED','VOIDED'))");

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION block_guest_payment_transaction_mutation()
RETURNS trigger AS $$
BEGIN
    IF OLD.property_id IS DISTINCT FROM NEW.property_id THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;
    IF OLD.payment_number IS DISTINCT FROM NEW.payment_number THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;
    IF OLD.reservation_id IS DISTINCT FROM NEW.reservation_id THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;
    IF OLD.guest_id IS DISTINCT FROM NEW.guest_id THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;
    IF OLD.currency IS DISTINCT FROM NEW.currency THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;
    IF OLD.amount IS DISTINCT FROM NEW.amount THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;
    IF OLD.tender_type IS DISTINCT FROM NEW.tender_type THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;
    IF OLD.cashier_session_id IS DISTINCT FROM NEW.cashier_session_id THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;
    IF OLD.recording_idempotency_key IS DISTINCT FROM NEW.recording_idempotency_key THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;
    IF OLD.recorded_at IS DISTINCT FROM NEW.recorded_at THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;
    IF OLD.recorded_by IS DISTINCT FROM NEW.recorded_by THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;
    IF OLD.source_snapshot::text IS DISTINCT FROM NEW.source_snapshot::text THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;
    IF OLD.created_at IS DISTINCT FROM NEW.created_at THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;
    IF OLD.created_by IS DISTINCT FROM NEW.created_by THEN
        RAISE EXCEPTION 'GLF_B_GUEST_PAYMENT_TRANSACTIONS_IMMUTABLE';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER guest_payment_transactions_immutable_trigger BEFORE UPDATE ON guest_payment_transactions FOR EACH ROW EXECUTE FUNCTION block_guest_payment_transaction_mutation()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS guest_payment_transactions_immutable_trigger ON guest_payment_transactions');
        DB::statement('DROP FUNCTION IF EXISTS block_guest_payment_transaction_mutation()');

        Schema::dropIfExists('guest_payment_transactions');

        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->dropUnique('cashier_sessions_property_id_unique');
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->dropUnique('guests_property_id_unique');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique('reservations_property_id_unique');
        });
    }
};
