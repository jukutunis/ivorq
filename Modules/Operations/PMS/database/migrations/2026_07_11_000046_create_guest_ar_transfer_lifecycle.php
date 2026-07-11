<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_ar_transfer_requests', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('transfer_number', 32);
            $table->char('folio_id', 26);
            $table->char('reservation_id', 26);
            $table->char('guest_id', 26);
            $table->string('currency', 3);
            $table->decimal('amount', 12, 2);
            $table->string('lifecycle_status', 20);
            $table->string('request_reason_code', 80);
            $table->string('request_idempotency_key', 96);
            $table->timestamp('requested_at');
            $table->char('requested_by', 26);
            $table->json('source_snapshot');
            $table->timestamps();
            $table->char('created_by', 26);
            $table->char('updated_by', 26)->nullable();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign(['property_id', 'folio_id'], 'guest_ar_requests_property_folio_foreign')
                ->references(['property_id', 'id'])->on('folios')->restrictOnDelete();
            $table->foreign(['property_id', 'reservation_id'], 'guest_ar_requests_property_reservation_foreign')
                ->references(['property_id', 'id'])->on('reservations')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_id'], 'guest_ar_requests_property_guest_foreign')
                ->references(['property_id', 'id'])->on('guests')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['property_id', 'id'], 'guest_ar_requests_property_id_unique');
            $table->unique(['property_id', 'transfer_number'], 'guest_ar_requests_property_number_unique');
            $table->unique(['property_id', 'request_idempotency_key'], 'guest_ar_requests_property_idem_unique');
        });
        DB::statement('ALTER TABLE guest_ar_transfer_requests ADD CONSTRAINT guest_ar_requests_amount_positive_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE guest_ar_transfer_requests ADD CONSTRAINT guest_ar_requests_status_check CHECK (lifecycle_status IN ('REQUESTED','ACCEPTED','REJECTED','REVERSED'))");

        Schema::create('guest_ar_transfer_decisions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('guest_ar_transfer_request_id', 26);
            $table->string('decision_type', 20);
            $table->char('reverses_decision_id', 26)->nullable();
            $table->string('reason_code', 80);
            $table->string('decision_idempotency_key', 96);
            $table->timestamp('decided_at');
            $table->char('decided_by', 26);
            $table->json('source_snapshot');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_ar_transfer_request_id'], 'guest_ar_decisions_property_request_foreign')
                ->references(['property_id', 'id'])->on('guest_ar_transfer_requests')->restrictOnDelete();
            $table->unique(['property_id', 'id'], 'guest_ar_decisions_property_id_unique');
            $table->foreign(['property_id', 'reverses_decision_id'], 'guest_ar_decisions_property_reverses_foreign')
                ->references(['property_id', 'id'])->on('guest_ar_transfer_decisions')->restrictOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['property_id', 'decision_idempotency_key'], 'guest_ar_decisions_property_idem_unique');
        });
        DB::statement("ALTER TABLE guest_ar_transfer_decisions ADD CONSTRAINT guest_ar_decisions_type_check CHECK (decision_type IN ('ACCEPTED','REJECTED','REVERSED'))");
        DB::statement("ALTER TABLE guest_ar_transfer_decisions ADD CONSTRAINT guest_ar_decisions_reversal_reference_check CHECK ((decision_type = 'REVERSED' AND reverses_decision_id IS NOT NULL) OR (decision_type IN ('ACCEPTED','REJECTED') AND reverses_decision_id IS NULL))");
        DB::statement("CREATE UNIQUE INDEX guest_ar_decisions_one_terminal_unique ON guest_ar_transfer_decisions (property_id, guest_ar_transfer_request_id) WHERE decision_type IN ('ACCEPTED','REJECTED')");
        DB::statement("CREATE UNIQUE INDEX guest_ar_decisions_one_reversal_unique ON guest_ar_transfer_decisions (property_id, reverses_decision_id) WHERE decision_type = 'REVERSED'");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION glf_c_validate_ar_decision()
RETURNS trigger AS $$
DECLARE original RECORD;
BEGIN
    IF NEW.decision_type = 'REVERSED' THEN
        SELECT * INTO original FROM guest_ar_transfer_decisions
         WHERE property_id = NEW.property_id
           AND id = NEW.reverses_decision_id
           AND guest_ar_transfer_request_id = NEW.guest_ar_transfer_request_id
           AND decision_type = 'ACCEPTED';
        IF original.id IS NULL THEN RAISE EXCEPTION 'GLF_C_AR_REVERSAL_SOURCE_INVALID'; END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE OR REPLACE FUNCTION block_glf_c_ar_request_identity_mutation()
RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'GLF_C_AR_REQUEST_IMMUTABLE'; END IF;
    IF OLD.property_id IS DISTINCT FROM NEW.property_id
       OR OLD.transfer_number IS DISTINCT FROM NEW.transfer_number
       OR OLD.folio_id IS DISTINCT FROM NEW.folio_id
       OR OLD.reservation_id IS DISTINCT FROM NEW.reservation_id
       OR OLD.guest_id IS DISTINCT FROM NEW.guest_id
       OR OLD.currency IS DISTINCT FROM NEW.currency
       OR OLD.amount IS DISTINCT FROM NEW.amount
       OR OLD.request_reason_code IS DISTINCT FROM NEW.request_reason_code
       OR OLD.request_idempotency_key IS DISTINCT FROM NEW.request_idempotency_key
       OR OLD.requested_at IS DISTINCT FROM NEW.requested_at
       OR OLD.requested_by IS DISTINCT FROM NEW.requested_by
       OR OLD.source_snapshot::text IS DISTINCT FROM NEW.source_snapshot::text
       OR OLD.created_at IS DISTINCT FROM NEW.created_at
       OR OLD.created_by IS DISTINCT FROM NEW.created_by THEN
        RAISE EXCEPTION 'GLF_C_AR_REQUEST_IMMUTABLE';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE OR REPLACE FUNCTION block_glf_c_ar_decision_mutation()
RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'GLF_C_AR_DECISION_IMMUTABLE'; END; $$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER guest_ar_decision_source_trigger BEFORE INSERT ON guest_ar_transfer_decisions FOR EACH ROW EXECUTE FUNCTION glf_c_validate_ar_decision()');
        DB::statement('CREATE TRIGGER guest_ar_requests_immutable_trigger BEFORE UPDATE OR DELETE ON guest_ar_transfer_requests FOR EACH ROW EXECUTE FUNCTION block_glf_c_ar_request_identity_mutation()');
        DB::statement('CREATE TRIGGER guest_ar_decisions_immutable_trigger BEFORE UPDATE OR DELETE ON guest_ar_transfer_decisions FOR EACH ROW EXECUTE FUNCTION block_glf_c_ar_decision_mutation()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS guest_ar_decisions_immutable_trigger ON guest_ar_transfer_decisions');
        DB::statement('DROP TRIGGER IF EXISTS guest_ar_requests_immutable_trigger ON guest_ar_transfer_requests');
        DB::statement('DROP TRIGGER IF EXISTS guest_ar_decision_source_trigger ON guest_ar_transfer_decisions');
        DB::statement('DROP FUNCTION IF EXISTS block_glf_c_ar_decision_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS block_glf_c_ar_request_identity_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS glf_c_validate_ar_decision()');
        Schema::dropIfExists('guest_ar_transfer_decisions');
        Schema::dropIfExists('guest_ar_transfer_requests');
    }
};
