<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyPaymentItems = DB::table('folio_items')
            ->whereIn('item_type', ['payment', 'deposit'])
            ->count();

        if ($legacyPaymentItems > 0) {
            throw new RuntimeException(
                "GLF_B_BLOCKED_LEGACY_PAYMENT_ITEMS: Found {$legacyPaymentItems} source-ambiguous Payment/Deposit FolioItem rows."
            );
        }

        Schema::table('folio_items', function (Blueprint $table) {
            $table->string('source_domain', 64)->nullable()->after('created_by');
            $table->string('source_type', 80)->nullable()->after('source_domain');
            $table->char('source_id', 26)->nullable()->after('source_type');
            $table->char('guest_payment_allocation_id', 26)->nullable()->after('source_id');
            $table->char('guest_payment_reversal_id', 26)->nullable()->after('guest_payment_allocation_id');
            $table->char('reverses_folio_item_id', 26)->nullable()->after('guest_payment_reversal_id');

            $table->unique(['property_id', 'id'], 'folio_items_property_id_unique');
            $table->unique(
                ['property_id', 'id', 'folio_id', 'guest_payment_allocation_id'],
                'folio_items_property_id_folio_allocation_unique'
            );
            $table->foreign(['property_id', 'reverses_folio_item_id'], 'folio_items_property_reverses_item_foreign')
                ->references(['property_id', 'id'])->on('folio_items')->restrictOnDelete();
            $table->foreign(['property_id', 'guest_payment_allocation_id'], 'folio_items_property_guest_allocation_foreign')
                ->references(['property_id', 'id'])->on('guest_payment_allocations')->restrictOnDelete();
            $table->foreign(
                ['property_id', 'guest_payment_reversal_id', 'guest_payment_allocation_id'],
                'folio_items_property_guest_reversal_foreign'
            )->references(['property_id', 'id', 'guest_payment_allocation_id'])->on('guest_payment_reversals')->restrictOnDelete();
            $table->unique(['property_id', 'source_domain', 'source_type', 'source_id'], 'folio_items_property_source_unique');
            $table->index(['property_id', 'source_domain', 'source_type'], 'folio_items_property_source_index');
        });

        DB::statement("ALTER TABLE folio_items ADD CONSTRAINT folio_items_source_all_or_none_check CHECK ((source_domain IS NULL AND source_type IS NULL AND source_id IS NULL) OR (source_domain IS NOT NULL AND source_type IS NOT NULL AND source_id IS NOT NULL))");
        DB::statement("ALTER TABLE folio_items ADD CONSTRAINT folio_items_guest_payment_source_check CHECK (
            (
                item_type = 'payment'
                AND source_domain = 'pms_cashiering'
                AND source_type = 'guest_payment_allocation'
                AND source_id = guest_payment_allocation_id
                AND guest_payment_allocation_id IS NOT NULL
                AND guest_payment_reversal_id IS NULL
                AND reverses_folio_item_id IS NULL
                AND amount < 0
            )
            OR (
                item_type = 'payment_reversal'
                AND source_domain = 'pms_cashiering'
                AND source_type = 'guest_payment_allocation_reversal'
                AND source_id = guest_payment_reversal_id
                AND guest_payment_allocation_id IS NOT NULL
                AND guest_payment_reversal_id IS NOT NULL
                AND reverses_folio_item_id IS NOT NULL
                AND amount > 0
            )
            OR (
                item_type NOT IN ('payment', 'payment_reversal')
                AND source_domain IS NULL
                AND source_type IS NULL
                AND source_id IS NULL
                AND guest_payment_allocation_id IS NULL
                AND guest_payment_reversal_id IS NULL
                AND reverses_folio_item_id IS NULL
            )
        )");
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION glf_b_folio_item_source_integrity()
RETURNS trigger AS $$
DECLARE
    original_item RECORD;
    allocation_row RECORD;
    reversal_row RECORD;
    expected_amount NUMERIC(12,2);
BEGIN
    IF NEW.item_type = 'payment' THEN
        SELECT property_id, id, folio_id, amount
          INTO allocation_row
          FROM guest_payment_allocations
         WHERE property_id = NEW.property_id
           AND id = NEW.guest_payment_allocation_id
           AND folio_id = NEW.folio_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'GLF_B_INVALID_PAYMENT_SOURCE';
        END IF;

        expected_amount := 0.00 - allocation_row.amount;
        IF NEW.amount <> expected_amount THEN
            RAISE EXCEPTION 'GLF_B_PAYMENT_EFFECT_AMOUNT_MISMATCH';
        END IF;

    ELSIF NEW.item_type = 'payment_reversal' THEN
        SELECT property_id, id, guest_payment_allocation_id, amount
          INTO reversal_row
          FROM guest_payment_reversals
         WHERE property_id = NEW.property_id
           AND id = NEW.guest_payment_reversal_id
           AND guest_payment_allocation_id = NEW.guest_payment_allocation_id
           AND reversal_type = 'ALLOCATION_REVERSAL';

        IF NOT FOUND THEN
            RAISE EXCEPTION 'GLF_B_INVALID_REVERSAL_SOURCE';
        END IF;

        SELECT a.amount AS allocation_amount
          INTO allocation_row
          FROM guest_payment_allocations a
         WHERE a.property_id = NEW.property_id
           AND a.id = NEW.guest_payment_allocation_id;

        expected_amount := 0.00 - allocation_row.allocation_amount;

        SELECT property_id, id, folio_id, guest_payment_allocation_id, item_type, amount
          INTO original_item
          FROM folio_items
         WHERE property_id = NEW.property_id
           AND id = NEW.reverses_folio_item_id
           AND folio_id = NEW.folio_id
           AND guest_payment_allocation_id = NEW.guest_payment_allocation_id
           AND item_type = 'payment';

        IF NOT FOUND THEN
            RAISE EXCEPTION 'GLF_B_INVALID_REVERSAL_TARGET';
        END IF;

        IF original_item.amount <> expected_amount THEN
            RAISE EXCEPTION 'GLF_B_PAYMENT_EFFECT_AMOUNT_MISMATCH';
        END IF;

        IF NEW.amount <> reversal_row.amount THEN
            RAISE EXCEPTION 'GLF_B_REVERSAL_EFFECT_AMOUNT_MISMATCH';
        END IF;

        IF reversal_row.amount <> allocation_row.allocation_amount THEN
            RAISE EXCEPTION 'GLF_B_REVERSAL_SOURCE_AMOUNT_MISMATCH';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement("CREATE TRIGGER glf_b_folio_item_source_integrity_trigger BEFORE INSERT OR UPDATE ON folio_items FOR EACH ROW EXECUTE FUNCTION glf_b_folio_item_source_integrity()");
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION block_glf_b_folio_item_source_mutation()
RETURNS trigger AS $$
BEGIN
    IF OLD.source_domain = 'pms_cashiering' THEN
        RAISE EXCEPTION 'GLF_B_FOLIO_ITEM_SOURCE_IMMUTABLE';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement("CREATE TRIGGER glf_b_folio_item_source_immutable_trigger BEFORE UPDATE OR DELETE ON folio_items FOR EACH ROW EXECUTE FUNCTION block_glf_b_folio_item_source_mutation()");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS glf_b_folio_item_source_immutable_trigger ON folio_items');
        DB::statement('DROP FUNCTION IF EXISTS block_glf_b_folio_item_source_mutation()');
        DB::statement('DROP TRIGGER IF EXISTS glf_b_folio_item_source_integrity_trigger ON folio_items');
        DB::statement('DROP FUNCTION IF EXISTS glf_b_folio_item_source_integrity()');
        DB::statement('ALTER TABLE folio_items DROP CONSTRAINT IF EXISTS folio_items_payment_reversal_link_check');
        DB::statement('ALTER TABLE folio_items DROP CONSTRAINT IF EXISTS folio_items_guest_payment_source_check');
        DB::statement('ALTER TABLE folio_items DROP CONSTRAINT IF EXISTS folio_items_source_all_or_none_check');

        Schema::table('folio_items', function (Blueprint $table) {
            $table->dropUnique('folio_items_property_source_unique');
            $table->dropIndex('folio_items_property_source_index');
            $table->dropForeign('folio_items_property_guest_reversal_foreign');
            $table->dropForeign('folio_items_property_guest_allocation_foreign');
            $table->dropForeign('folio_items_property_reverses_item_foreign');
            $table->dropUnique('folio_items_property_id_folio_allocation_unique');
            $table->dropUnique('folio_items_property_id_unique');
            $table->dropColumn([
                'source_domain',
                'source_type',
                'source_id',
                'guest_payment_allocation_id',
                'guest_payment_reversal_id',
                'reverses_folio_item_id',
            ]);
        });
    }
};
