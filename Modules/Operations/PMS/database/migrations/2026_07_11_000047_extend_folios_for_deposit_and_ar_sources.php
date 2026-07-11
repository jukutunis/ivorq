<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyDeposits = DB::table('folio_items')
            ->where('item_type', 'deposit')
            ->whereNull('guest_payment_allocation_id')
            ->count();

        if ($legacyDeposits > 0) {
            throw new RuntimeException(
                "GLF_C_BLOCKED_LEGACY_DEPOSIT_ITEMS: Found {$legacyDeposits} source-ambiguous Deposit FolioItem rows."
            );
        }

        Schema::table('folios', function (Blueprint $table) {
            $table->decimal('total_deposits', 12, 2)->default(0)->after('total_payments');
            $table->decimal('total_ar_transfers', 12, 2)->default(0)->after('total_deposits');
        });

        DB::statement('ALTER TABLE folio_items DROP CONSTRAINT folio_items_guest_payment_source_check');

        Schema::table('folio_items', function (Blueprint $table) {
            $table->char('guest_deposit_application_id', 26)->nullable()->after('guest_payment_reversal_id');
            $table->char('guest_deposit_reversal_id', 26)->nullable()->after('guest_deposit_application_id');
            $table->char('guest_ar_transfer_decision_id', 26)->nullable()->after('guest_deposit_reversal_id');

            $table->foreign(['property_id', 'guest_deposit_application_id'], 'folio_items_property_deposit_application_foreign')
                ->references(['property_id', 'id'])->on('guest_deposit_applications')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_deposit_reversal_id'], 'folio_items_property_deposit_reversal_foreign')
                ->references(['property_id', 'id'])->on('guest_deposit_reversals')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_ar_transfer_decision_id'], 'folio_items_property_ar_decision_foreign')
                ->references(['property_id', 'id'])->on('guest_ar_transfer_decisions')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
ALTER TABLE folio_items ADD CONSTRAINT folio_items_glf_c_source_check CHECK (
    (
        item_type = 'payment'
        AND source_domain = 'pms_cashiering'
        AND source_type = 'guest_payment_allocation'
        AND source_id = guest_payment_allocation_id
        AND guest_payment_allocation_id IS NOT NULL
        AND guest_payment_reversal_id IS NULL
        AND guest_deposit_application_id IS NULL
        AND guest_deposit_reversal_id IS NULL
        AND guest_ar_transfer_decision_id IS NULL
        AND reverses_folio_item_id IS NULL
        AND amount < 0
    ) OR (
        item_type = 'payment_reversal'
        AND source_domain = 'pms_cashiering'
        AND source_type = 'guest_payment_allocation_reversal'
        AND source_id = guest_payment_reversal_id
        AND guest_payment_allocation_id IS NOT NULL
        AND guest_payment_reversal_id IS NOT NULL
        AND guest_deposit_application_id IS NULL
        AND guest_deposit_reversal_id IS NULL
        AND guest_ar_transfer_decision_id IS NULL
        AND reverses_folio_item_id IS NOT NULL
        AND amount > 0
    ) OR (
        item_type = 'deposit'
        AND source_domain = 'pms_cashiering'
        AND source_type = 'guest_deposit_application'
        AND source_id = guest_deposit_application_id
        AND guest_payment_allocation_id IS NULL
        AND guest_payment_reversal_id IS NULL
        AND guest_deposit_application_id IS NOT NULL
        AND guest_deposit_reversal_id IS NULL
        AND guest_ar_transfer_decision_id IS NULL
        AND reverses_folio_item_id IS NULL
        AND amount < 0
    ) OR (
        item_type = 'deposit_reversal'
        AND source_domain = 'pms_cashiering'
        AND source_type = 'guest_deposit_application_reversal'
        AND source_id = guest_deposit_reversal_id
        AND guest_payment_allocation_id IS NULL
        AND guest_payment_reversal_id IS NULL
        AND guest_deposit_application_id IS NOT NULL
        AND guest_deposit_reversal_id IS NOT NULL
        AND guest_ar_transfer_decision_id IS NULL
        AND reverses_folio_item_id IS NOT NULL
        AND amount > 0
    ) OR (
        item_type = 'ar_transfer'
        AND source_domain = 'accounting_ar'
        AND source_type = 'guest_ar_transfer_acceptance'
        AND source_id = guest_ar_transfer_decision_id
        AND guest_payment_allocation_id IS NULL
        AND guest_payment_reversal_id IS NULL
        AND guest_deposit_application_id IS NULL
        AND guest_deposit_reversal_id IS NULL
        AND guest_ar_transfer_decision_id IS NOT NULL
        AND reverses_folio_item_id IS NULL
        AND amount < 0
    ) OR (
        item_type = 'ar_transfer_reversal'
        AND source_domain = 'accounting_ar'
        AND source_type = 'guest_ar_transfer_reversal'
        AND source_id = guest_ar_transfer_decision_id
        AND guest_payment_allocation_id IS NULL
        AND guest_payment_reversal_id IS NULL
        AND guest_deposit_application_id IS NULL
        AND guest_deposit_reversal_id IS NULL
        AND guest_ar_transfer_decision_id IS NOT NULL
        AND reverses_folio_item_id IS NOT NULL
        AND amount > 0
    ) OR (
        item_type NOT IN ('payment','payment_reversal','deposit','deposit_reversal','ar_transfer','ar_transfer_reversal')
        AND source_domain IS NULL AND source_type IS NULL AND source_id IS NULL
        AND guest_payment_allocation_id IS NULL AND guest_payment_reversal_id IS NULL
        AND guest_deposit_application_id IS NULL AND guest_deposit_reversal_id IS NULL
        AND guest_ar_transfer_decision_id IS NULL AND reverses_folio_item_id IS NULL
    )
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION glf_c_folio_item_source_integrity()
RETURNS trigger AS $$
DECLARE
    source_row RECORD;
    original_item RECORD;
    expected_amount NUMERIC(12,2);
    accepted_decision_id CHAR(26);
BEGIN
    IF NEW.item_type = 'payment' THEN
        SELECT * INTO source_row FROM guest_payment_allocations
         WHERE property_id = NEW.property_id AND id = NEW.guest_payment_allocation_id AND folio_id = NEW.folio_id;
        expected_amount := 0.00 - source_row.amount;
    ELSIF NEW.item_type = 'payment_reversal' THEN
        SELECT r.*, a.amount AS source_amount INTO source_row
          FROM guest_payment_reversals r JOIN guest_payment_allocations a
            ON a.property_id = r.property_id AND a.id = r.guest_payment_allocation_id
         WHERE r.property_id = NEW.property_id AND r.id = NEW.guest_payment_reversal_id
           AND r.guest_payment_allocation_id = NEW.guest_payment_allocation_id
           AND r.reversal_type = 'ALLOCATION_REVERSAL';
        expected_amount := source_row.source_amount;
    ELSIF NEW.item_type = 'deposit' THEN
        SELECT * INTO source_row FROM guest_deposit_applications
         WHERE property_id = NEW.property_id AND id = NEW.guest_deposit_application_id AND folio_id = NEW.folio_id;
        expected_amount := 0.00 - source_row.amount;
    ELSIF NEW.item_type = 'deposit_reversal' THEN
        SELECT r.*, a.amount AS source_amount, a.folio_id INTO source_row
          FROM guest_deposit_reversals r JOIN guest_deposit_applications a
            ON a.property_id = r.property_id AND a.id = r.guest_deposit_application_id
         WHERE r.property_id = NEW.property_id AND r.id = NEW.guest_deposit_reversal_id
           AND r.guest_deposit_application_id = NEW.guest_deposit_application_id
           AND r.reversal_type = 'APPLICATION_REVERSAL' AND a.folio_id = NEW.folio_id;
        expected_amount := source_row.source_amount;
    ELSIF NEW.item_type = 'ar_transfer' THEN
        SELECT d.*, r.amount AS source_amount, r.folio_id INTO source_row
          FROM guest_ar_transfer_decisions d JOIN guest_ar_transfer_requests r
            ON r.property_id = d.property_id AND r.id = d.guest_ar_transfer_request_id
         WHERE d.property_id = NEW.property_id AND d.id = NEW.guest_ar_transfer_decision_id
           AND d.decision_type = 'ACCEPTED' AND r.folio_id = NEW.folio_id;
        expected_amount := 0.00 - source_row.source_amount;
    ELSIF NEW.item_type = 'ar_transfer_reversal' THEN
        SELECT d.*, r.amount AS source_amount, r.folio_id INTO source_row
          FROM guest_ar_transfer_decisions d
          JOIN guest_ar_transfer_decisions accepted
            ON accepted.property_id = d.property_id AND accepted.id = d.reverses_decision_id AND accepted.decision_type = 'ACCEPTED'
          JOIN guest_ar_transfer_requests r
            ON r.property_id = accepted.property_id AND r.id = accepted.guest_ar_transfer_request_id
         WHERE d.property_id = NEW.property_id AND d.id = NEW.guest_ar_transfer_decision_id
           AND d.decision_type = 'REVERSED' AND r.folio_id = NEW.folio_id;
        expected_amount := source_row.source_amount;
        accepted_decision_id := source_row.reverses_decision_id;
    ELSE
        RETURN NEW;
    END IF;

    IF NEW.item_type = 'payment' THEN
        IF source_row.id IS NULL THEN RAISE EXCEPTION 'GLF_B_INVALID_PAYMENT_SOURCE'; END IF;
        IF NEW.amount <> expected_amount THEN RAISE EXCEPTION 'GLF_B_PAYMENT_EFFECT_AMOUNT_MISMATCH'; END IF;
    ELSIF NEW.item_type = 'payment_reversal' THEN
        IF source_row.id IS NULL THEN RAISE EXCEPTION 'GLF_B_INVALID_REVERSAL_SOURCE'; END IF;
        IF NEW.amount <> expected_amount THEN RAISE EXCEPTION 'GLF_B_REVERSAL_EFFECT_AMOUNT_MISMATCH'; END IF;
    ELSIF source_row.id IS NULL OR NEW.amount <> expected_amount THEN
        RAISE EXCEPTION 'GLF_C_FOLIO_ITEM_SOURCE_AMOUNT_MISMATCH';
    END IF;

    IF NEW.reverses_folio_item_id IS NOT NULL THEN
        SELECT * INTO original_item FROM folio_items
         WHERE property_id = NEW.property_id AND id = NEW.reverses_folio_item_id AND folio_id = NEW.folio_id;
        IF original_item.id IS NULL THEN RAISE EXCEPTION 'GLF_C_FOLIO_ITEM_REVERSAL_TARGET_INVALID'; END IF;
        IF NEW.item_type = 'payment_reversal'
           AND (original_item.item_type <> 'payment' OR original_item.guest_payment_allocation_id <> NEW.guest_payment_allocation_id) THEN
            RAISE EXCEPTION 'GLF_C_FOLIO_ITEM_REVERSAL_TARGET_INVALID';
        END IF;
        IF NEW.item_type = 'deposit_reversal'
           AND (original_item.item_type <> 'deposit' OR original_item.guest_deposit_application_id <> NEW.guest_deposit_application_id) THEN
            RAISE EXCEPTION 'GLF_C_FOLIO_ITEM_REVERSAL_TARGET_INVALID';
        END IF;
        IF NEW.item_type = 'ar_transfer_reversal'
           AND (original_item.item_type <> 'ar_transfer'
                OR original_item.guest_ar_transfer_decision_id <> accepted_decision_id) THEN
            RAISE EXCEPTION 'GLF_C_FOLIO_ITEM_REVERSAL_TARGET_INVALID';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('DROP TRIGGER glf_b_folio_item_source_integrity_trigger ON folio_items');
        DB::statement('DROP FUNCTION glf_b_folio_item_source_integrity()');
        DB::statement('CREATE TRIGGER glf_c_folio_item_source_integrity_trigger BEFORE INSERT OR UPDATE ON folio_items FOR EACH ROW EXECUTE FUNCTION glf_c_folio_item_source_integrity()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION block_glf_c_folio_item_source_mutation()
RETURNS trigger AS $$
BEGIN
    IF OLD.source_domain IN ('pms_cashiering','accounting_ar') THEN
        RAISE EXCEPTION 'GLF_C_FOLIO_ITEM_SOURCE_IMMUTABLE';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('DROP TRIGGER glf_b_folio_item_source_immutable_trigger ON folio_items');
        DB::statement('DROP FUNCTION block_glf_b_folio_item_source_mutation()');
        DB::statement('CREATE TRIGGER glf_c_folio_item_source_immutable_trigger BEFORE UPDATE OR DELETE ON folio_items FOR EACH ROW EXECUTE FUNCTION block_glf_c_folio_item_source_mutation()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS glf_c_folio_item_source_immutable_trigger ON folio_items');
        DB::statement('DROP FUNCTION IF EXISTS block_glf_c_folio_item_source_mutation()');
        DB::statement('DROP TRIGGER IF EXISTS glf_c_folio_item_source_integrity_trigger ON folio_items');
        DB::statement('DROP FUNCTION IF EXISTS glf_c_folio_item_source_integrity()');
        DB::statement('ALTER TABLE folio_items DROP CONSTRAINT IF EXISTS folio_items_glf_c_source_check');

        DB::table('folio_items')
            ->whereIn('item_type', ['deposit', 'deposit_reversal', 'ar_transfer', 'ar_transfer_reversal'])
            ->delete();

        Schema::table('folio_items', function (Blueprint $table) {
            $table->dropForeign('folio_items_property_ar_decision_foreign');
            $table->dropForeign('folio_items_property_deposit_reversal_foreign');
            $table->dropForeign('folio_items_property_deposit_application_foreign');
            $table->dropColumn([
                'guest_deposit_application_id',
                'guest_deposit_reversal_id',
                'guest_ar_transfer_decision_id',
            ]);
        });

        DB::statement(<<<'SQL'
ALTER TABLE folio_items ADD CONSTRAINT folio_items_guest_payment_source_check CHECK (
    (item_type = 'payment' AND source_domain = 'pms_cashiering' AND source_type = 'guest_payment_allocation'
     AND source_id = guest_payment_allocation_id AND guest_payment_allocation_id IS NOT NULL
     AND guest_payment_reversal_id IS NULL AND reverses_folio_item_id IS NULL AND amount < 0)
 OR (item_type = 'payment_reversal' AND source_domain = 'pms_cashiering'
     AND source_type = 'guest_payment_allocation_reversal' AND source_id = guest_payment_reversal_id
     AND guest_payment_allocation_id IS NOT NULL AND guest_payment_reversal_id IS NOT NULL
     AND reverses_folio_item_id IS NOT NULL AND amount > 0)
 OR (item_type NOT IN ('payment','payment_reversal') AND source_domain IS NULL AND source_type IS NULL
     AND source_id IS NULL AND guest_payment_allocation_id IS NULL AND guest_payment_reversal_id IS NULL
     AND reverses_folio_item_id IS NULL)
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION glf_b_folio_item_source_integrity()
RETURNS trigger AS $$
DECLARE original_item RECORD; allocation_row RECORD; reversal_row RECORD; expected_amount NUMERIC(12,2);
BEGIN
    IF NEW.item_type = 'payment' THEN
        SELECT * INTO allocation_row FROM guest_payment_allocations
         WHERE property_id = NEW.property_id AND id = NEW.guest_payment_allocation_id AND folio_id = NEW.folio_id;
        expected_amount := 0.00 - allocation_row.amount;
        IF allocation_row.id IS NULL OR NEW.amount <> expected_amount THEN RAISE EXCEPTION 'GLF_B_PAYMENT_EFFECT_AMOUNT_MISMATCH'; END IF;
    ELSIF NEW.item_type = 'payment_reversal' THEN
        SELECT * INTO reversal_row FROM guest_payment_reversals
         WHERE property_id = NEW.property_id AND id = NEW.guest_payment_reversal_id
           AND guest_payment_allocation_id = NEW.guest_payment_allocation_id AND reversal_type = 'ALLOCATION_REVERSAL';
        SELECT * INTO allocation_row FROM guest_payment_allocations
         WHERE property_id = NEW.property_id AND id = NEW.guest_payment_allocation_id;
        SELECT * INTO original_item FROM folio_items
         WHERE property_id = NEW.property_id AND id = NEW.reverses_folio_item_id
           AND folio_id = NEW.folio_id AND guest_payment_allocation_id = NEW.guest_payment_allocation_id AND item_type = 'payment';
        IF reversal_row.id IS NULL OR allocation_row.id IS NULL OR original_item.id IS NULL
           OR NEW.amount <> reversal_row.amount OR reversal_row.amount <> allocation_row.amount THEN
            RAISE EXCEPTION 'GLF_B_REVERSAL_EFFECT_AMOUNT_MISMATCH';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE OR REPLACE FUNCTION block_glf_b_folio_item_source_mutation()
RETURNS trigger AS $$
BEGIN
    IF OLD.source_domain = 'pms_cashiering' THEN RAISE EXCEPTION 'GLF_B_FOLIO_ITEM_SOURCE_IMMUTABLE'; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER glf_b_folio_item_source_integrity_trigger BEFORE INSERT OR UPDATE ON folio_items FOR EACH ROW EXECUTE FUNCTION glf_b_folio_item_source_integrity()');
        DB::statement('CREATE TRIGGER glf_b_folio_item_source_immutable_trigger BEFORE UPDATE OR DELETE ON folio_items FOR EACH ROW EXECUTE FUNCTION block_glf_b_folio_item_source_mutation()');

        Schema::table('folios', function (Blueprint $table) {
            $table->dropColumn(['total_deposits', 'total_ar_transfers']);
        });
    }
};
