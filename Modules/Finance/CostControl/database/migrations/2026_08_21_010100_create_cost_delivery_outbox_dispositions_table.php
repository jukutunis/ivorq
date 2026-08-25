<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_delivery_outbox_dispositions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('outbox_message_id', 26);
            $table->char('source_inventory_transaction_id', 26);
            $table->char('property_id', 26);
            $table->char('location_id', 26);
            $table->char('item_id', 26);
            $table->string('valuation_scope');
            $table->unsignedBigInteger('valuation_sequence');
            $table->string('classification', 64);
            $table->string('processing_state', 32);
            $table->char('cost_delivery_ownership_id', 26)->nullable();
            $table->unsignedBigInteger('cost_delivery_ownership_version')->nullable();
            $table->char('cost_delivery_cutover_id', 26)->nullable();
            $table->char('equivalent_cost_ledger_entry_id', 26)->nullable();
            $table->char('classified_by', 26);
            $table->string('classification_provenance', 96);
            $table->timestampTz('classified_at');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('last_attempted_at')->nullable();
            $table->string('last_failure_code', 96)->nullable();
            $table->boolean('is_recoverable')->nullable();
            $table->unsignedBigInteger('expected_sequence')->nullable();
            $table->timestampTz('historical_excluded_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->unique('outbox_message_id', 'uk_cdod_outbox_message');
            $table->unique('source_inventory_transaction_id', 'uk_cdod_source_transaction');
            $table->index(
                ['property_id', 'item_id', 'processing_state'],
                'idx_cdod_property_item_state',
            );
            $table->index(
                ['property_id', 'valuation_scope', 'valuation_sequence'],
                'idx_cdod_property_scope_sequence',
            );

            $table->foreign('cost_delivery_ownership_id', 'fk_cdod_ownership')
                ->references('id')->on('cost_delivery_mode_ownerships')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('cost_delivery_cutover_id', 'fk_cdod_cutover')
                ->references('id')->on('cost_delivery_cutovers')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('equivalent_cost_ledger_entry_id', 'fk_cdod_cost_ledger')
                ->references('id')->on('cost_ledger_entries')
                ->restrictOnDelete()->restrictOnUpdate();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE cost_delivery_outbox_dispositions
            ADD CONSTRAINT chk_cdod_classification CHECK (classification IN (
                'SYNCHRONOUSLY_SATISFIED_HISTORY',
                'UNENROLLED_OR_NON_COSTCONTROL_ELIGIBLE_HISTORY',
                'DEFERRED_OWNED_AFTER_CUTOVER'
            ))");
        DB::statement("ALTER TABLE cost_delivery_outbox_dispositions
            ADD CONSTRAINT chk_cdod_processing_state CHECK (processing_state IN (
                'HISTORICAL_EXCLUDED', 'PENDING', 'DELIVERED', 'FAILED', 'BLOCKED_SEQUENCE'
            ))");
        DB::statement('ALTER TABLE cost_delivery_outbox_dispositions
            ADD CONSTRAINT chk_cdod_positive_sequence CHECK (valuation_sequence >= 1)');
        DB::statement("ALTER TABLE cost_delivery_outbox_dispositions
            ADD CONSTRAINT chk_cdod_provenance CHECK (
                btrim(classified_by) <> ''
                AND btrim(classification_provenance) <> ''
            )");
        DB::statement("ALTER TABLE cost_delivery_outbox_dispositions
            ADD CONSTRAINT chk_cdod_classification_shape CHECK (
                (classification = 'SYNCHRONOUSLY_SATISFIED_HISTORY'
                    AND processing_state = 'HISTORICAL_EXCLUDED'
                    AND equivalent_cost_ledger_entry_id IS NOT NULL
                    AND cost_delivery_cutover_id IS NULL
                    AND ((cost_delivery_ownership_id IS NULL AND cost_delivery_ownership_version IS NULL)
                        OR (cost_delivery_ownership_id IS NOT NULL AND cost_delivery_ownership_version >= 1))
                    AND historical_excluded_at IS NOT NULL)
                OR
                (classification = 'UNENROLLED_OR_NON_COSTCONTROL_ELIGIBLE_HISTORY'
                    AND processing_state = 'HISTORICAL_EXCLUDED'
                    AND cost_delivery_ownership_id IS NULL
                    AND cost_delivery_ownership_version IS NULL
                    AND cost_delivery_cutover_id IS NULL
                    AND equivalent_cost_ledger_entry_id IS NULL
                    AND historical_excluded_at IS NOT NULL)
                OR
                (classification = 'DEFERRED_OWNED_AFTER_CUTOVER'
                    AND processing_state IN ('PENDING', 'DELIVERED', 'FAILED', 'BLOCKED_SEQUENCE')
                    AND cost_delivery_ownership_id IS NOT NULL
                    AND cost_delivery_ownership_version >= 1
                    AND cost_delivery_cutover_id IS NOT NULL
                    AND equivalent_cost_ledger_entry_id IS NULL
                    AND historical_excluded_at IS NULL)
            )");
        DB::statement("ALTER TABLE cost_delivery_outbox_dispositions
            ADD CONSTRAINT chk_cdod_processing_shape CHECK (
                (processing_state = 'HISTORICAL_EXCLUDED'
                    AND attempt_count = 0
                    AND last_attempted_at IS NULL
                    AND last_failure_code IS NULL
                    AND is_recoverable IS NULL
                    AND expected_sequence IS NULL
                    AND delivered_at IS NULL)
                OR
                (processing_state = 'PENDING'
                    AND ((attempt_count = 0 AND last_attempted_at IS NULL)
                        OR (attempt_count > 0 AND last_attempted_at IS NOT NULL))
                    AND last_failure_code IS NULL
                    AND is_recoverable IS NULL
                    AND expected_sequence IS NULL
                    AND delivered_at IS NULL)
                OR
                (processing_state = 'FAILED'
                    AND attempt_count > 0
                    AND last_attempted_at IS NOT NULL
                    AND last_failure_code IS NOT NULL
                    AND btrim(last_failure_code) <> ''
                    AND is_recoverable IS NOT NULL
                    AND expected_sequence IS NULL
                    AND delivered_at IS NULL)
                OR
                (processing_state = 'BLOCKED_SEQUENCE'
                    AND attempt_count > 0
                    AND last_attempted_at IS NOT NULL
                    AND last_failure_code = 'BLOCKED_SEQUENCE'
                    AND is_recoverable = TRUE
                    AND expected_sequence >= 1
                    AND delivered_at IS NULL)
                OR
                (processing_state = 'DELIVERED'
                    AND attempt_count > 0
                    AND last_attempted_at IS NOT NULL
                    AND last_failure_code IS NULL
                    AND is_recoverable IS NULL
                    AND expected_sequence IS NULL
                    AND delivered_at IS NOT NULL)
            )");
        DB::statement("CREATE INDEX idx_cdod_active_future_work
            ON cost_delivery_outbox_dispositions
                (property_id, processing_state, valuation_scope, valuation_sequence)
            WHERE processing_state IN ('PENDING', 'FAILED', 'BLOCKED_SEQUENCE')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_cdod_insert()
            RETURNS trigger AS $$
            DECLARE
                ledger_row record;
                ownership_row record;
                cutover_row record;
            BEGIN
                IF NEW.classification IN (
                    'SYNCHRONOUSLY_SATISFIED_HISTORY',
                    'UNENROLLED_OR_NON_COSTCONTROL_ELIGIBLE_HISTORY'
                ) AND NEW.processing_state <> 'HISTORICAL_EXCLUDED' THEN
                    RAISE EXCEPTION 'cost_delivery_outbox_dispositions: historical classification must begin HISTORICAL_EXCLUDED'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.classification = 'DEFERRED_OWNED_AFTER_CUTOVER' THEN
                    IF NEW.processing_state <> 'PENDING' THEN
                        RAISE EXCEPTION 'cost_delivery_outbox_dispositions: deferred classification must begin PENDING'
                            USING ERRCODE = '23514';
                    END IF;

                    SELECT * INTO ownership_row FROM cost_delivery_mode_ownerships
                     WHERE id = NEW.cost_delivery_ownership_id;
                    SELECT * INTO cutover_row FROM cost_delivery_cutovers
                     WHERE id = NEW.cost_delivery_cutover_id;

                    IF ownership_row.id IS NULL
                       OR cutover_row.id IS NULL
                       OR ownership_row.delivery_mode <> 'DEFERRED'
                       OR ownership_row.ownership_version IS DISTINCT FROM NEW.cost_delivery_ownership_version
                       OR ownership_row.activated_cutover_id IS DISTINCT FROM NEW.cost_delivery_cutover_id
                       OR ownership_row.property_id IS DISTINCT FROM NEW.property_id
                       OR ownership_row.item_id IS DISTINCT FROM NEW.item_id
                       OR cutover_row.ownership_id IS DISTINCT FROM NEW.cost_delivery_ownership_id
                       OR cutover_row.property_id IS DISTINCT FROM NEW.property_id
                       OR cutover_row.item_id IS DISTINCT FROM NEW.item_id THEN
                        RAISE EXCEPTION 'cost_delivery_outbox_dispositions: deferred ownership/cutover evidence mismatch'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                IF NEW.classification = 'SYNCHRONOUSLY_SATISFIED_HISTORY' THEN
                    SELECT * INTO ledger_row FROM cost_ledger_entries
                     WHERE id = NEW.equivalent_cost_ledger_entry_id;

                    IF ledger_row.id IS NULL
                       OR ledger_row.property_id IS DISTINCT FROM NEW.property_id
                       OR ledger_row.source_inventory_transaction_id IS DISTINCT FROM NEW.source_inventory_transaction_id
                       OR ledger_row.entry_sequence IS DISTINCT FROM NEW.valuation_sequence THEN
                        RAISE EXCEPTION 'cost_delivery_outbox_dispositions: equivalent Cost Ledger evidence mismatch'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION guard_cdod_lifecycle()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'cost_delivery_outbox_dispositions: deletion is prohibited';
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                   OR NEW.outbox_message_id IS DISTINCT FROM OLD.outbox_message_id
                   OR NEW.source_inventory_transaction_id IS DISTINCT FROM OLD.source_inventory_transaction_id
                   OR NEW.property_id IS DISTINCT FROM OLD.property_id
                   OR NEW.location_id IS DISTINCT FROM OLD.location_id
                   OR NEW.item_id IS DISTINCT FROM OLD.item_id
                   OR NEW.valuation_scope IS DISTINCT FROM OLD.valuation_scope
                   OR NEW.valuation_sequence IS DISTINCT FROM OLD.valuation_sequence
                   OR NEW.classification IS DISTINCT FROM OLD.classification
                   OR NEW.cost_delivery_ownership_id IS DISTINCT FROM OLD.cost_delivery_ownership_id
                   OR NEW.cost_delivery_ownership_version IS DISTINCT FROM OLD.cost_delivery_ownership_version
                   OR NEW.cost_delivery_cutover_id IS DISTINCT FROM OLD.cost_delivery_cutover_id
                   OR NEW.equivalent_cost_ledger_entry_id IS DISTINCT FROM OLD.equivalent_cost_ledger_entry_id
                   OR NEW.classified_by IS DISTINCT FROM OLD.classified_by
                   OR NEW.classification_provenance IS DISTINCT FROM OLD.classification_provenance
                   OR NEW.classified_at IS DISTINCT FROM OLD.classified_at
                   OR NEW.historical_excluded_at IS DISTINCT FROM OLD.historical_excluded_at
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'cost_delivery_outbox_dispositions: classification and source evidence are immutable';
                END IF;

                IF OLD.processing_state IN ('HISTORICAL_EXCLUDED', 'DELIVERED') THEN
                    RAISE EXCEPTION 'cost_delivery_outbox_dispositions: terminal state % is immutable', OLD.processing_state;
                END IF;

                IF OLD.processing_state = 'PENDING'
                   AND NEW.processing_state NOT IN ('FAILED', 'BLOCKED_SEQUENCE', 'DELIVERED') THEN
                    RAISE EXCEPTION 'cost_delivery_outbox_dispositions: invalid transition PENDING->%', NEW.processing_state;
                END IF;

                IF OLD.processing_state IN ('FAILED', 'BLOCKED_SEQUENCE')
                   AND NEW.processing_state <> 'PENDING' THEN
                    RAISE EXCEPTION 'cost_delivery_outbox_dispositions: recovery must return to PENDING';
                END IF;

                IF OLD.processing_state = 'PENDING' THEN
                    IF NEW.attempt_count <> OLD.attempt_count + 1
                       OR NEW.last_attempted_at IS NULL
                       OR NEW.last_attempted_at IS NOT DISTINCT FROM OLD.last_attempted_at THEN
                        RAISE EXCEPTION 'cost_delivery_outbox_dispositions: processing outcome requires one recorded attempt';
                    END IF;
                ELSE
                    IF NEW.attempt_count IS DISTINCT FROM OLD.attempt_count
                       OR NEW.last_attempted_at IS DISTINCT FROM OLD.last_attempted_at THEN
                        RAISE EXCEPTION 'cost_delivery_outbox_dispositions: recovery cannot fabricate an attempt';
                    END IF;
                END IF;

                IF NEW.updated_at < OLD.updated_at THEN
                    RAISE EXCEPTION 'cost_delivery_outbox_dispositions: updated_at cannot move backwards';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_cdod_insert
            BEFORE INSERT ON cost_delivery_outbox_dispositions
            FOR EACH ROW EXECUTE FUNCTION guard_cdod_insert();

            CREATE TRIGGER trg_cdod_lifecycle
            BEFORE UPDATE OR DELETE ON cost_delivery_outbox_dispositions
            FOR EACH ROW EXECUTE FUNCTION guard_cdod_lifecycle();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_cdod_insert ON cost_delivery_outbox_dispositions');
            DB::statement('DROP TRIGGER IF EXISTS trg_cdod_lifecycle ON cost_delivery_outbox_dispositions');
            DB::statement('DROP FUNCTION IF EXISTS guard_cdod_insert()');
            DB::statement('DROP FUNCTION IF EXISTS guard_cdod_lifecycle()');
        }

        Schema::dropIfExists('cost_delivery_outbox_dispositions');
    }
};
