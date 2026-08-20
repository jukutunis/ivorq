<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_delivery_cutovers', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('ownership_id', 26);
            $table->char('enrollment_group_id', 26);
            $table->char('property_id', 26);
            $table->char('item_id', 26);
            $table->char('financial_period_id', 26);
            $table->date('boundary_business_date');
            $table->string('owner_approval_reference');
            $table->char('requested_by', 26);
            $table->timestampTz('requested_at');
            $table->char('approved_by', 26);
            $table->timestampTz('approved_at');
            $table->char('activated_by', 26);
            $table->timestampTz('activated_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('ownership_id', 'uk_cdc_ownership');
            $table->unique('enrollment_group_id', 'uk_cdc_enrollment_group');
            $table->foreign('ownership_id', 'fk_cdc_ownership')
                ->references('id')->on('cost_delivery_mode_ownerships')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('enrollment_group_id', 'fk_cdc_enrollment')
                ->references('id')->on('cost_authority_enrollment_groups')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('property_id', 'fk_cdc_property')
                ->references('id')->on('properties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('item_id', 'fk_cdc_item')
                ->references('id')->on('inventory_items')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('financial_period_id', 'fk_cdc_period')
                ->references('id')->on('gl_financial_periods')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::table('cost_delivery_mode_ownerships', function (Blueprint $table) {
            $table->foreign('activated_cutover_id', 'fk_cdmo_cutover')
                ->references('id')->on('cost_delivery_cutovers')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('cost_delivery_cutover_scopes', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('cutover_id', 26);
            $table->char('enrollment_scope_snapshot_id', 26);
            $table->char('property_id', 26);
            $table->char('location_id', 26);
            $table->char('item_id', 26);
            $table->string('valuation_scope');
            $table->string('inventory_sequence_source', 24);
            $table->char('inventory_valuation_sequence_id', 26)->nullable();
            $table->unsignedBigInteger('inventory_allocator_last_sequence');
            $table->unsignedBigInteger('cost_avco_last_valuation_sequence')->nullable();
            $table->string('sequence_state_classification', 48);
            $table->unsignedBigInteger('last_synchronously_owned_sequence');
            $table->unsignedBigInteger('first_deferred_owned_sequence');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['cutover_id', 'location_id'], 'uk_cdcs_cutover_location');
            $table->unique(['cutover_id', 'valuation_scope'], 'uk_cdcs_cutover_scope');
            $table->unique('enrollment_scope_snapshot_id', 'uk_cdcs_snapshot');
            $table->foreign('cutover_id', 'fk_cdcs_cutover')
                ->references('id')->on('cost_delivery_cutovers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('enrollment_scope_snapshot_id', 'fk_cdcs_snapshot')
                ->references('id')->on('cost_authority_enrollment_scope_snapshots')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('property_id', 'fk_cdcs_property')
                ->references('id')->on('properties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('location_id', 'fk_cdcs_location')
                ->references('id')->on('inventory_locations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('item_id', 'fk_cdcs_item')
                ->references('id')->on('inventory_items')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('cost_delivery_cutover_attempts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('request_id', 26);
            $table->char('property_id', 26);
            $table->char('item_id', 26);
            $table->char('enrollment_group_id', 26);
            $table->char('target_financial_period_id', 26);
            $table->date('boundary_business_date');
            $table->string('outcome', 24);
            $table->string('reason_code')->nullable();
            $table->char('cutover_id', 26)->nullable();
            $table->string('owner_approval_reference');
            $table->char('requested_by', 26);
            $table->timestampTz('requested_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('request_id', 'uk_cdca_request');
            $table->index(['property_id', 'item_id', 'outcome'], 'idx_cdca_property_item_outcome');
            $table->foreign('property_id', 'fk_cdca_property')
                ->references('id')->on('properties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('item_id', 'fk_cdca_item')
                ->references('id')->on('inventory_items')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('enrollment_group_id', 'fk_cdca_enrollment')
                ->references('id')->on('cost_authority_enrollment_groups')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('target_financial_period_id', 'fk_cdca_period')
                ->references('id')->on('gl_financial_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('cutover_id', 'fk_cdca_cutover')
                ->references('id')->on('cost_delivery_cutovers')->restrictOnDelete()->restrictOnUpdate();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE cost_delivery_cutovers ADD CONSTRAINT chk_cdc_provenance CHECK (
            btrim(owner_approval_reference) <> ''
            AND btrim(requested_by) <> ''
            AND btrim(approved_by) <> ''
            AND btrim(activated_by) <> ''
            AND requested_at <= approved_at
            AND approved_at <= activated_at
        )");
        DB::statement("ALTER TABLE cost_delivery_cutover_scopes ADD CONSTRAINT chk_cdcs_sequence_source CHECK (
            (inventory_sequence_source = 'ALLOCATOR_ABSENT' AND inventory_valuation_sequence_id IS NULL)
            OR (inventory_sequence_source = 'ALLOCATOR_ROW' AND inventory_valuation_sequence_id IS NOT NULL)
        )");
        DB::statement("ALTER TABLE cost_delivery_cutover_scopes ADD CONSTRAINT chk_cdcs_state_class CHECK (
            sequence_state_classification IN ('NO_PRIOR_APPLIED_VALUATION_SEQUENCE', 'PRIOR_APPLIED_VALUATION_SEQUENCE')
        )");
        DB::statement('ALTER TABLE cost_delivery_cutover_scopes ADD CONSTRAINT chk_cdcs_n_plus_one CHECK (
            first_deferred_owned_sequence = last_synchronously_owned_sequence + 1
        )');
        DB::statement("ALTER TABLE cost_delivery_cutover_scopes ADD CONSTRAINT chk_cdcs_state_shape CHECK (
            (sequence_state_classification = 'NO_PRIOR_APPLIED_VALUATION_SEQUENCE'
                AND inventory_allocator_last_sequence = 0
                AND cost_avco_last_valuation_sequence IS NULL
                AND last_synchronously_owned_sequence = 0
                AND first_deferred_owned_sequence = 1)
            OR
            (sequence_state_classification = 'PRIOR_APPLIED_VALUATION_SEQUENCE'
                AND inventory_allocator_last_sequence > 0
                AND cost_avco_last_valuation_sequence = inventory_allocator_last_sequence
                AND last_synchronously_owned_sequence = inventory_allocator_last_sequence)
        )");
        DB::statement("ALTER TABLE cost_delivery_cutover_attempts ADD CONSTRAINT chk_cdca_outcome CHECK (
            (outcome = 'ACTIVATED' AND cutover_id IS NOT NULL AND reason_code IS NULL)
            OR (outcome = 'CUTOVER_BLOCKED' AND cutover_id IS NULL AND reason_code IS NOT NULL AND btrim(reason_code) <> '')
        )");
        DB::statement("ALTER TABLE cost_delivery_cutover_attempts ADD CONSTRAINT chk_cdca_provenance CHECK (
            btrim(owner_approval_reference) <> '' AND btrim(requested_by) <> ''
        )");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_cdc_identity()
            RETURNS trigger AS $$
            DECLARE
                owner_row record;
                period_property text;
                pilot_matches boolean;
            BEGIN
                SELECT * INTO owner_row
                  FROM cost_delivery_mode_ownerships
                 WHERE id = NEW.ownership_id;

                IF owner_row.id IS NULL
                   OR owner_row.enrollment_group_id IS DISTINCT FROM NEW.enrollment_group_id
                   OR owner_row.property_id IS DISTINCT FROM NEW.property_id
                   OR owner_row.item_id IS DISTINCT FROM NEW.item_id THEN
                    RAISE EXCEPTION 'cost_delivery_cutovers: ownership identity mismatch'
                        USING ERRCODE = '23514';
                END IF;

                SELECT property_id INTO period_property
                  FROM gl_financial_periods
                 WHERE id = NEW.financial_period_id;

                IF period_property IS DISTINCT FROM NEW.property_id THEN
                    RAISE EXCEPTION 'cost_delivery_cutovers: Financial Period Property mismatch'
                        USING ERRCODE = '23514';
                END IF;

                SELECT EXISTS(
                    SELECT 1 FROM cost_delivery_pilot_properties
                     WHERE pilot_slot = 1 AND property_id = NEW.property_id
                ) INTO pilot_matches;

                IF NOT pilot_matches THEN
                    RAISE EXCEPTION 'cost_delivery_cutovers: Property is not the authorized pilot'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION guard_cdcs_identity_and_sequence()
            RETURNS trigger AS $$
            DECLARE
                cutoff record;
                snapshot record;
                allocator record;
                avco record;
                positive_source_exists boolean;
                max_source_sequence bigint;
                expected_scope text;
            BEGIN
                SELECT * INTO cutoff FROM cost_delivery_cutovers WHERE id = NEW.cutover_id;
                SELECT * INTO snapshot FROM cost_authority_enrollment_scope_snapshots
                 WHERE id = NEW.enrollment_scope_snapshot_id;

                expected_scope := 'property:' || NEW.property_id
                               || ':location:' || NEW.location_id
                               || ':item:' || NEW.item_id;

                IF cutoff.id IS NULL OR snapshot.id IS NULL
                   OR cutoff.enrollment_group_id IS DISTINCT FROM snapshot.enrollment_group_id
                   OR cutoff.property_id IS DISTINCT FROM NEW.property_id
                   OR cutoff.item_id IS DISTINCT FROM NEW.item_id
                   OR snapshot.location_id IS DISTINCT FROM NEW.location_id
                   OR snapshot.valuation_scope IS DISTINCT FROM NEW.valuation_scope
                   OR NEW.valuation_scope IS DISTINCT FROM expected_scope THEN
                    RAISE EXCEPTION 'cost_delivery_cutover_scopes: scope/enrollment identity mismatch'
                        USING ERRCODE = '23514';
                END IF;

                SELECT * INTO allocator
                  FROM inventory_valuation_sequences
                 WHERE property_id = NEW.property_id
                   AND location_id = NEW.location_id
                   AND item_id = NEW.item_id;

                IF NEW.inventory_sequence_source = 'ALLOCATOR_ABSENT' THEN
                    IF allocator.id IS NOT NULL THEN
                        RAISE EXCEPTION 'CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE: allocator expected absent'
                            USING ERRCODE = '23514';
                    END IF;
                ELSE
                    IF allocator.id IS NULL
                       OR allocator.id IS DISTINCT FROM NEW.inventory_valuation_sequence_id
                       OR allocator.last_sequence IS DISTINCT FROM NEW.inventory_allocator_last_sequence THEN
                        RAISE EXCEPTION 'CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE: allocator evidence mismatch'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                SELECT * INTO avco
                  FROM cost_avco_states
                 WHERE property_id = NEW.property_id
                   AND location_id = NEW.location_id
                   AND item_id = NEW.item_id;

                IF avco.id IS NULL
                   OR avco.enrollment_group_id IS DISTINCT FROM cutoff.enrollment_group_id
                   OR avco.enrollment_scope_snapshot_id IS DISTINCT FROM snapshot.id
                   OR avco.last_valuation_sequence IS DISTINCT FROM NEW.cost_avco_last_valuation_sequence THEN
                    RAISE EXCEPTION 'CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE: AVCO evidence mismatch'
                        USING ERRCODE = '23514';
                END IF;

                SELECT EXISTS(
                    SELECT 1 FROM inventory_transactions
                     WHERE property_id = NEW.property_id
                       AND location_id = NEW.location_id
                       AND item_id = NEW.item_id
                       AND valuation_scope = NEW.valuation_scope
                       AND valuation_sequence > 0
                ) INTO positive_source_exists;

                SELECT max(valuation_sequence) INTO max_source_sequence
                  FROM inventory_transactions
                 WHERE property_id = NEW.property_id
                   AND location_id = NEW.location_id
                   AND item_id = NEW.item_id
                   AND valuation_scope = NEW.valuation_scope
                   AND valuation_sequence > 0;

                IF NEW.sequence_state_classification = 'NO_PRIOR_APPLIED_VALUATION_SEQUENCE' THEN
                    IF NEW.cost_avco_last_valuation_sequence IS NOT NULL
                       OR NEW.inventory_allocator_last_sequence <> 0
                       OR positive_source_exists THEN
                        RAISE EXCEPTION 'CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE: virgin scope not proven'
                            USING ERRCODE = '23514';
                    END IF;
                ELSE
                    IF NEW.cost_avco_last_valuation_sequence IS NULL
                       OR NEW.inventory_allocator_last_sequence = 0
                       OR NEW.inventory_allocator_last_sequence IS DISTINCT FROM NEW.cost_avco_last_valuation_sequence
                       OR NOT positive_source_exists
                       OR max_source_sequence IS DISTINCT FROM NEW.inventory_allocator_last_sequence THEN
                        RAISE EXCEPTION 'CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE: non-virgin sequences differ'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION guard_cdce_immutable()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '%: immutable append-only evidence', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION enforce_cdc_complete_scope()
            RETURNS trigger AS $$
            DECLARE
                cutoff_id_value text;
                cutoff record;
                owner_row record;
                snapshot_count bigint;
                scope_count bigint;
                mismatch_exists boolean;
            BEGIN
                IF TG_TABLE_NAME = 'cost_delivery_cutovers' THEN
                    cutoff_id_value := NEW.id;
                ELSE
                    cutoff_id_value := NEW.cutover_id;
                END IF;
                SELECT * INTO cutoff FROM cost_delivery_cutovers WHERE id = cutoff_id_value;

                IF cutoff.id IS NULL THEN
                    RETURN NULL;
                END IF;

                SELECT * INTO owner_row FROM cost_delivery_mode_ownerships WHERE id = cutoff.ownership_id;
                IF owner_row.delivery_mode IS DISTINCT FROM 'DEFERRED'
                   OR owner_row.activated_cutover_id IS DISTINCT FROM cutoff.id THEN
                    RAISE EXCEPTION 'cost_delivery_cutovers: ownership linkage is incomplete'
                        USING ERRCODE = '23514';
                END IF;

                SELECT count(*) INTO snapshot_count
                  FROM cost_authority_enrollment_scope_snapshots
                 WHERE enrollment_group_id = cutoff.enrollment_group_id;
                SELECT count(*) INTO scope_count
                  FROM cost_delivery_cutover_scopes
                 WHERE cutover_id = cutoff.id;

                SELECT EXISTS(
                    SELECT 1
                      FROM cost_authority_enrollment_scope_snapshots s
                      LEFT JOIN cost_delivery_cutover_scopes c
                        ON c.cutover_id = cutoff.id
                       AND c.enrollment_scope_snapshot_id = s.id
                       AND c.location_id = s.location_id
                       AND c.valuation_scope = s.valuation_scope
                     WHERE s.enrollment_group_id = cutoff.enrollment_group_id
                       AND c.id IS NULL
                ) INTO mismatch_exists;

                IF snapshot_count = 0 OR scope_count <> snapshot_count OR mismatch_exists THEN
                    RAISE EXCEPTION 'cost_delivery_cutovers: complete enrollment scope coverage is required'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_cdc_identity
            BEFORE INSERT ON cost_delivery_cutovers
            FOR EACH ROW EXECUTE FUNCTION guard_cdc_identity();

            CREATE TRIGGER trg_cdcs_identity_sequence
            BEFORE INSERT ON cost_delivery_cutover_scopes
            FOR EACH ROW EXECUTE FUNCTION guard_cdcs_identity_and_sequence();

            CREATE TRIGGER trg_cdc_no_update_delete
            BEFORE UPDATE OR DELETE ON cost_delivery_cutovers
            FOR EACH ROW EXECUTE FUNCTION guard_cdce_immutable();

            CREATE TRIGGER trg_cdcs_no_update_delete
            BEFORE UPDATE OR DELETE ON cost_delivery_cutover_scopes
            FOR EACH ROW EXECUTE FUNCTION guard_cdce_immutable();

            CREATE TRIGGER trg_cdca_no_update_delete
            BEFORE UPDATE OR DELETE ON cost_delivery_cutover_attempts
            FOR EACH ROW EXECUTE FUNCTION guard_cdce_immutable();

            CREATE CONSTRAINT TRIGGER trg_cdc_complete_scope
            AFTER INSERT ON cost_delivery_cutovers
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION enforce_cdc_complete_scope();

            CREATE CONSTRAINT TRIGGER trg_cdcs_complete_scope
            AFTER INSERT ON cost_delivery_cutover_scopes
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION enforce_cdc_complete_scope();
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('cost_delivery_mode_ownerships')) {
            Schema::table('cost_delivery_mode_ownerships', function (Blueprint $table) {
                $table->dropForeign('fk_cdmo_cutover');
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS enforce_cdc_complete_scope() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS guard_cdc_identity() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS guard_cdcs_identity_and_sequence() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS guard_cdce_immutable() CASCADE');
        }

        Schema::dropIfExists('cost_delivery_cutover_attempts');
        Schema::dropIfExists('cost_delivery_cutover_scopes');
        Schema::dropIfExists('cost_delivery_cutovers');
    }
};
