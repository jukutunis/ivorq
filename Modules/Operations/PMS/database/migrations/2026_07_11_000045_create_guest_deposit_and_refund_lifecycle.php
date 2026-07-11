<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyDeposits = DB::table('folio_items')->where('item_type', 'deposit')->count();
        if ($legacyDeposits > 0) {
            throw new RuntimeException(
                "GLF_C_BLOCKED_LEGACY_DEPOSIT_ITEMS: Found {$legacyDeposits} source-ambiguous Deposit FolioItem rows."
            );
        }

        Schema::create('guest_deposit_transactions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('deposit_number', 32);
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
            $table->foreign(['property_id', 'reservation_id'], 'guest_deposits_property_reservation_foreign')
                ->references(['property_id', 'id'])->on('reservations')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_id'], 'guest_deposits_property_guest_foreign')
                ->references(['property_id', 'id'])->on('guests')->restrictOnDelete();
            $table->foreign(['property_id', 'cashier_session_id'], 'guest_deposits_property_session_foreign')
                ->references(['property_id', 'id'])->on('cashier_sessions')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'id'], 'guest_deposits_property_id_unique');
            $table->unique(['property_id', 'deposit_number'], 'guest_deposits_property_number_unique');
            $table->unique(['property_id', 'recording_idempotency_key'], 'guest_deposits_property_idem_unique');
            $table->index(['property_id', 'reservation_id'], 'guest_deposits_property_reservation_index');
        });

        DB::statement("ALTER TABLE guest_deposit_transactions ADD CONSTRAINT guest_deposits_amount_positive_check CHECK (amount > 0)");
        DB::statement("ALTER TABLE guest_deposit_transactions ADD CONSTRAINT guest_deposits_tender_check CHECK (tender_type = 'CASH')");
        DB::statement("ALTER TABLE guest_deposit_transactions ADD CONSTRAINT guest_deposits_status_check CHECK (lifecycle_status IN ('RECORDED','PARTIALLY_RESOLVED','RESOLVED','VOIDED'))");

        Schema::create('guest_deposit_applications', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('guest_deposit_transaction_id', 26);
            $table->char('folio_id', 26);
            $table->decimal('amount', 12, 2);
            $table->string('application_idempotency_key', 96);
            $table->timestamp('applied_at');
            $table->char('applied_by', 26);
            $table->json('source_snapshot');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_deposit_transaction_id'], 'guest_deposit_apps_property_deposit_foreign')
                ->references(['property_id', 'id'])->on('guest_deposit_transactions')->restrictOnDelete();
            $table->foreign(['property_id', 'folio_id'], 'guest_deposit_apps_property_folio_foreign')
                ->references(['property_id', 'id'])->on('folios')->restrictOnDelete();
            $table->foreign('applied_by')->references('id')->on('users')->restrictOnDelete();

            $table->unique(['property_id', 'id'], 'guest_deposit_apps_property_id_unique');
            $table->unique(['property_id', 'id', 'guest_deposit_transaction_id'], 'guest_deposit_apps_property_id_deposit_unique');
            $table->unique(['property_id', 'application_idempotency_key'], 'guest_deposit_apps_property_idem_unique');
            $table->index(['property_id', 'guest_deposit_transaction_id'], 'guest_deposit_apps_property_deposit_index');
        });
        DB::statement('ALTER TABLE guest_deposit_applications ADD CONSTRAINT guest_deposit_apps_amount_positive_check CHECK (amount > 0)');

        Schema::create('guest_deposit_reversals', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('guest_deposit_transaction_id', 26);
            $table->char('guest_deposit_application_id', 26)->nullable();
            $table->string('reversal_type', 32);
            $table->decimal('amount', 12, 2);
            $table->string('reason_code', 80);
            $table->string('reversal_idempotency_key', 96);
            $table->timestamp('reversed_at');
            $table->char('reversed_by', 26);
            $table->json('source_snapshot');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_deposit_transaction_id'], 'guest_deposit_reversals_property_deposit_foreign')
                ->references(['property_id', 'id'])->on('guest_deposit_transactions')->restrictOnDelete();
            $table->foreign(
                ['property_id', 'guest_deposit_application_id', 'guest_deposit_transaction_id'],
                'guest_deposit_reversals_property_application_foreign'
            )->references(['property_id', 'id', 'guest_deposit_transaction_id'])->on('guest_deposit_applications')->restrictOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->restrictOnDelete();

            $table->unique(['property_id', 'id'], 'guest_deposit_reversals_property_id_unique');
            $table->unique(['property_id', 'reversal_idempotency_key'], 'guest_deposit_reversals_property_idem_unique');
        });
        DB::statement('ALTER TABLE guest_deposit_reversals ADD CONSTRAINT guest_deposit_reversals_amount_positive_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE guest_deposit_reversals ADD CONSTRAINT guest_deposit_reversals_type_check CHECK (reversal_type IN ('DEPOSIT_VOID','APPLICATION_REVERSAL'))");
        DB::statement("ALTER TABLE guest_deposit_reversals ADD CONSTRAINT guest_deposit_reversals_reference_check CHECK ((reversal_type = 'DEPOSIT_VOID' AND guest_deposit_application_id IS NULL) OR (reversal_type = 'APPLICATION_REVERSAL' AND guest_deposit_application_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX guest_deposit_reversals_one_void_unique ON guest_deposit_reversals (property_id, guest_deposit_transaction_id) WHERE reversal_type = 'DEPOSIT_VOID'");
        DB::statement("CREATE UNIQUE INDEX guest_deposit_reversals_one_application_reversal_unique ON guest_deposit_reversals (property_id, guest_deposit_application_id) WHERE reversal_type = 'APPLICATION_REVERSAL'");

        Schema::create('guest_refund_transactions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('refund_number', 32);
            $table->char('reservation_id', 26);
            $table->char('guest_id', 26);
            $table->string('currency', 3);
            $table->decimal('amount', 12, 2);
            $table->string('tender_type', 20);
            $table->char('cashier_session_id', 26);
            $table->string('refund_source_type', 32);
            $table->char('guest_payment_transaction_id', 26)->nullable();
            $table->char('guest_deposit_transaction_id', 26)->nullable();
            $table->string('reason_code', 80);
            $table->string('refund_idempotency_key', 96);
            $table->timestamp('refunded_at');
            $table->char('refunded_by', 26);
            $table->json('source_snapshot');
            $table->timestamp('created_at')->useCurrent();
            $table->char('created_by', 26);

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign(['property_id', 'reservation_id'], 'guest_refunds_property_reservation_foreign')
                ->references(['property_id', 'id'])->on('reservations')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_id'], 'guest_refunds_property_guest_foreign')
                ->references(['property_id', 'id'])->on('guests')->restrictOnDelete();
            $table->foreign(['property_id', 'cashier_session_id'], 'guest_refunds_property_session_foreign')
                ->references(['property_id', 'id'])->on('cashier_sessions')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_payment_transaction_id'], 'guest_refunds_property_payment_foreign')
                ->references(['property_id', 'id'])->on('guest_payment_transactions')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_deposit_transaction_id'], 'guest_refunds_property_deposit_foreign')
                ->references(['property_id', 'id'])->on('guest_deposit_transactions')->restrictOnDelete();
            $table->foreign('refunded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->unique(['property_id', 'id'], 'guest_refunds_property_id_unique');
            $table->unique(['property_id', 'refund_number'], 'guest_refunds_property_number_unique');
            $table->unique(['property_id', 'refund_idempotency_key'], 'guest_refunds_property_idem_unique');
        });
        DB::statement('ALTER TABLE guest_refund_transactions ADD CONSTRAINT guest_refunds_amount_positive_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE guest_refund_transactions ADD CONSTRAINT guest_refunds_tender_check CHECK (tender_type = 'CASH')");
        DB::statement("ALTER TABLE guest_refund_transactions ADD CONSTRAINT guest_refunds_source_type_check CHECK (refund_source_type IN ('GUEST_PAYMENT','GUEST_DEPOSIT'))");
        DB::statement("ALTER TABLE guest_refund_transactions ADD CONSTRAINT guest_refunds_source_xor_check CHECK ((refund_source_type = 'GUEST_PAYMENT' AND guest_payment_transaction_id IS NOT NULL AND guest_deposit_transaction_id IS NULL) OR (refund_source_type = 'GUEST_DEPOSIT' AND guest_payment_transaction_id IS NULL AND guest_deposit_transaction_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION glf_c_validate_deposit_application()
RETURNS trigger AS $$
DECLARE
    deposit_row RECORD;
    folio_row RECORD;
    active_applications NUMERIC(12,2);
    completed_refunds NUMERIC(12,2);
BEGIN
    SELECT * INTO deposit_row FROM guest_deposit_transactions
     WHERE property_id = NEW.property_id AND id = NEW.guest_deposit_transaction_id;
    SELECT * INTO folio_row FROM folios
     WHERE property_id = NEW.property_id AND id = NEW.folio_id;
    IF NOT FOUND OR deposit_row.id IS NULL OR folio_row.id IS NULL THEN
        RAISE EXCEPTION 'GLF_C_DEPOSIT_APPLICATION_SOURCE_UNAVAILABLE';
    END IF;
    IF deposit_row.lifecycle_status = 'VOIDED' OR folio_row.status <> 'open'
       OR deposit_row.reservation_id <> folio_row.reservation_id
       OR deposit_row.guest_id <> folio_row.guest_id
       OR deposit_row.currency <> folio_row.currency THEN
        RAISE EXCEPTION 'GLF_C_DEPOSIT_APPLICATION_SOURCE_MISMATCH';
    END IF;
    SELECT COALESCE(SUM(a.amount), 0.00) INTO active_applications
      FROM guest_deposit_applications a
     WHERE a.property_id = NEW.property_id
       AND a.guest_deposit_transaction_id = NEW.guest_deposit_transaction_id
       AND NOT EXISTS (
           SELECT 1 FROM guest_deposit_reversals r
            WHERE r.property_id = a.property_id
              AND r.guest_deposit_application_id = a.id
              AND r.reversal_type = 'APPLICATION_REVERSAL'
       );
    SELECT COALESCE(SUM(amount), 0.00) INTO completed_refunds
      FROM guest_refund_transactions
     WHERE property_id = NEW.property_id
       AND guest_deposit_transaction_id = NEW.guest_deposit_transaction_id;
    IF active_applications + completed_refunds + NEW.amount > deposit_row.amount THEN
        RAISE EXCEPTION 'GLF_C_DEPOSIT_OVER_APPLICATION';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER glf_c_deposit_application_source_trigger BEFORE INSERT ON guest_deposit_applications FOR EACH ROW EXECUTE FUNCTION glf_c_validate_deposit_application()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION glf_c_validate_deposit_reversal()
RETURNS trigger AS $$
DECLARE source_amount NUMERIC(12,2); source_status TEXT;
BEGIN
    IF NEW.reversal_type = 'DEPOSIT_VOID' THEN
        SELECT amount, lifecycle_status INTO source_amount, source_status FROM guest_deposit_transactions
         WHERE property_id = NEW.property_id AND id = NEW.guest_deposit_transaction_id;
        IF source_status = 'VOIDED'
           OR EXISTS (SELECT 1 FROM guest_deposit_applications WHERE property_id = NEW.property_id AND guest_deposit_transaction_id = NEW.guest_deposit_transaction_id)
           OR EXISTS (SELECT 1 FROM guest_refund_transactions WHERE property_id = NEW.property_id AND guest_deposit_transaction_id = NEW.guest_deposit_transaction_id) THEN
            RAISE EXCEPTION 'GLF_C_DEPOSIT_VOID_HISTORY_CONFLICT';
        END IF;
    ELSE
        SELECT amount INTO source_amount FROM guest_deposit_applications
         WHERE property_id = NEW.property_id
           AND id = NEW.guest_deposit_application_id
           AND guest_deposit_transaction_id = NEW.guest_deposit_transaction_id;
    END IF;
    IF source_amount IS NULL OR NEW.amount <> source_amount THEN
        RAISE EXCEPTION 'GLF_C_DEPOSIT_REVERSAL_SOURCE_AMOUNT_MISMATCH';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER glf_c_deposit_reversal_source_trigger BEFORE INSERT ON guest_deposit_reversals FOR EACH ROW EXECUTE FUNCTION glf_c_validate_deposit_reversal()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION glf_c_validate_refund_source()
RETURNS trigger AS $$
DECLARE
    source_row RECORD;
    committed_amount NUMERIC(12,2);
    refunded_amount NUMERIC(12,2);
BEGIN
    IF NEW.refund_source_type = 'GUEST_PAYMENT' THEN
        SELECT * INTO source_row FROM guest_payment_transactions
         WHERE property_id = NEW.property_id AND id = NEW.guest_payment_transaction_id;
        SELECT COALESCE(SUM(a.amount), 0.00) INTO committed_amount
          FROM guest_payment_allocations a
         WHERE a.property_id = NEW.property_id
           AND a.guest_payment_transaction_id = NEW.guest_payment_transaction_id
           AND NOT EXISTS (SELECT 1 FROM guest_payment_reversals r
                WHERE r.property_id = a.property_id AND r.guest_payment_allocation_id = a.id
                  AND r.reversal_type = 'ALLOCATION_REVERSAL');
        SELECT COALESCE(SUM(amount), 0.00) INTO refunded_amount FROM guest_refund_transactions
         WHERE property_id = NEW.property_id AND guest_payment_transaction_id = NEW.guest_payment_transaction_id;
    ELSE
        SELECT * INTO source_row FROM guest_deposit_transactions
         WHERE property_id = NEW.property_id AND id = NEW.guest_deposit_transaction_id;
        SELECT COALESCE(SUM(a.amount), 0.00) INTO committed_amount
          FROM guest_deposit_applications a
         WHERE a.property_id = NEW.property_id
           AND a.guest_deposit_transaction_id = NEW.guest_deposit_transaction_id
           AND NOT EXISTS (SELECT 1 FROM guest_deposit_reversals r
                WHERE r.property_id = a.property_id AND r.guest_deposit_application_id = a.id
                  AND r.reversal_type = 'APPLICATION_REVERSAL');
        SELECT COALESCE(SUM(amount), 0.00) INTO refunded_amount FROM guest_refund_transactions
         WHERE property_id = NEW.property_id AND guest_deposit_transaction_id = NEW.guest_deposit_transaction_id;
    END IF;
    IF source_row.id IS NULL OR source_row.lifecycle_status = 'VOIDED'
       OR source_row.reservation_id <> NEW.reservation_id
       OR source_row.guest_id <> NEW.guest_id OR source_row.currency <> NEW.currency THEN
        RAISE EXCEPTION 'GLF_C_REFUND_SOURCE_MISMATCH';
    END IF;
    IF committed_amount + refunded_amount + NEW.amount > source_row.amount THEN
        RAISE EXCEPTION 'GLF_C_REFUND_EXCEEDS_AVAILABLE_SOURCE';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER glf_c_refund_source_trigger BEFORE INSERT ON guest_refund_transactions FOR EACH ROW EXECUTE FUNCTION glf_c_validate_refund_source()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION block_glf_c_deposit_identity_mutation()
RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'GLF_C_GUEST_DEPOSIT_IMMUTABLE'; END IF;
    IF OLD.property_id IS DISTINCT FROM NEW.property_id
       OR OLD.deposit_number IS DISTINCT FROM NEW.deposit_number
       OR OLD.reservation_id IS DISTINCT FROM NEW.reservation_id
       OR OLD.guest_id IS DISTINCT FROM NEW.guest_id
       OR OLD.currency IS DISTINCT FROM NEW.currency
       OR OLD.amount IS DISTINCT FROM NEW.amount
       OR OLD.tender_type IS DISTINCT FROM NEW.tender_type
       OR OLD.cashier_session_id IS DISTINCT FROM NEW.cashier_session_id
       OR OLD.recording_idempotency_key IS DISTINCT FROM NEW.recording_idempotency_key
       OR OLD.recorded_at IS DISTINCT FROM NEW.recorded_at
       OR OLD.recorded_by IS DISTINCT FROM NEW.recorded_by
       OR OLD.source_snapshot::text IS DISTINCT FROM NEW.source_snapshot::text
       OR OLD.created_at IS DISTINCT FROM NEW.created_at
       OR OLD.created_by IS DISTINCT FROM NEW.created_by THEN
        RAISE EXCEPTION 'GLF_C_GUEST_DEPOSIT_IMMUTABLE';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE OR REPLACE FUNCTION block_glf_c_immutable_row()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'GLF_C_FINANCIAL_EVIDENCE_IMMUTABLE';
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER guest_deposits_immutable_trigger BEFORE UPDATE OR DELETE ON guest_deposit_transactions FOR EACH ROW EXECUTE FUNCTION block_glf_c_deposit_identity_mutation()');
        foreach (['guest_deposit_applications', 'guest_deposit_reversals', 'guest_refund_transactions'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable_trigger BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION block_glf_c_immutable_row()");
        }
    }

    public function down(): void
    {
        foreach (['guest_refund_transactions', 'guest_deposit_reversals', 'guest_deposit_applications'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_immutable_trigger ON {$table}");
        }
        DB::statement('DROP TRIGGER IF EXISTS guest_deposits_immutable_trigger ON guest_deposit_transactions');
        DB::statement('DROP TRIGGER IF EXISTS glf_c_refund_source_trigger ON guest_refund_transactions');
        DB::statement('DROP TRIGGER IF EXISTS glf_c_deposit_reversal_source_trigger ON guest_deposit_reversals');
        DB::statement('DROP TRIGGER IF EXISTS glf_c_deposit_application_source_trigger ON guest_deposit_applications');
        DB::statement('DROP FUNCTION IF EXISTS block_glf_c_immutable_row()');
        DB::statement('DROP FUNCTION IF EXISTS block_glf_c_deposit_identity_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS glf_c_validate_refund_source()');
        DB::statement('DROP FUNCTION IF EXISTS glf_c_validate_deposit_reversal()');
        DB::statement('DROP FUNCTION IF EXISTS glf_c_validate_deposit_application()');
        Schema::dropIfExists('guest_refund_transactions');
        Schema::dropIfExists('guest_deposit_reversals');
        Schema::dropIfExists('guest_deposit_applications');
        Schema::dropIfExists('guest_deposit_transactions');
    }
};
