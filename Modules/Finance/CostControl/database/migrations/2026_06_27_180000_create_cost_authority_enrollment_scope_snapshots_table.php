<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_authority_enrollment_scope_snapshots', function (Blueprint $table) {
            $table->char('id', 26)->primary();

            // Internal FK to parent CostControl-owned group table only
            $table->char('enrollment_group_id', 26);
            $table->foreign('enrollment_group_id')
                  ->references('id')
                  ->on('cost_authority_enrollment_groups')
                  ->restrictOnDelete();

            // Scope identity — no cross-module FKs for location_id, financial_period_id
            $table->char('location_id', 26);
            $table->string('valuation_scope');

            // Reconciled opening evidence — decimal(15,4) matches CostControl convention
            // (cost_avco_states: on_hand_quantity, carrying_value use decimal(15,4))
            $table->decimal('opening_quantity', 15, 4);
            $table->decimal('opening_carrying_value', 15, 4);

            // Currency and period context — plain references, no cross-module FKs
            $table->char('currency_code', 3);
            $table->date('business_date');
            $table->char('financial_period_id', 26)->nullable();

            // Audit provenance of the reconciled evidence
            $table->string('source_reference')->nullable();
            $table->timestampTz('evidence_timestamp');

            $table->timestamps();

            // One snapshot per location+scope per group
            $table->unique(
                ['enrollment_group_id', 'location_id', 'valuation_scope'],
                'uk_caess_group_location_scope'
            );
        });

        if (DB::getDriverName() === 'pgsql') {

            // Non-negative CHECK constraints
            DB::statement(
                'ALTER TABLE cost_authority_enrollment_scope_snapshots
                 ADD CONSTRAINT chk_caess_opening_quantity_non_negative
                 CHECK (opening_quantity >= 0)'
            );

            DB::statement(
                'ALTER TABLE cost_authority_enrollment_scope_snapshots
                 ADD CONSTRAINT chk_caess_opening_carrying_value_non_negative
                 CHECK (opening_carrying_value >= 0)'
            );

            // ----------------------------------------------------------------
            // Snapshot lifecycle trigger: deny writes when parent is not draft
            // ----------------------------------------------------------------
            DB::statement("
                CREATE OR REPLACE FUNCTION guard_caess_parent_draft()
                RETURNS TRIGGER AS \$\$
                DECLARE
                    parent_status TEXT;
                    group_id_col  TEXT;
                BEGIN
                    -- Resolve the group id from the row being operated on
                    IF TG_OP = 'DELETE' THEN
                        group_id_col := OLD.enrollment_group_id;
                    ELSE
                        group_id_col := NEW.enrollment_group_id;
                    END IF;

                    SELECT status INTO parent_status
                    FROM cost_authority_enrollment_groups
                    WHERE id = group_id_col;

                    IF parent_status IS NULL THEN
                        RAISE EXCEPTION 'cost_authority_enrollment_scope_snapshots: parent enrollment group not found';
                    END IF;

                    IF parent_status <> 'draft' THEN
                        RAISE EXCEPTION 'cost_authority_enrollment_scope_snapshots: snapshot changes are not allowed when parent status=%', parent_status;
                    END IF;

                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END;
                \$\$ LANGUAGE plpgsql;
            ");

            DB::statement("
                CREATE TRIGGER trg_caess_guard_parent_draft
                BEFORE INSERT OR UPDATE OR DELETE ON cost_authority_enrollment_scope_snapshots
                FOR EACH ROW EXECUTE FUNCTION guard_caess_parent_draft();
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_caess_guard_parent_draft ON cost_authority_enrollment_scope_snapshots');
            DB::statement('DROP FUNCTION IF EXISTS guard_caess_parent_draft()');
        }

        Schema::dropIfExists('cost_authority_enrollment_scope_snapshots');
    }
};
