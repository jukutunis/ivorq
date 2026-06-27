<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // ----------------------------------------------------------------
        // A. Replace the three-column unique index
        //    (enrollment_group_id, location_id, valuation_scope)
        //    with a two-column unique index
        //    (enrollment_group_id, location_id).
        //
        //    One location_id per group is the canonical identity invariant.
        //    If existing data already violates this, migration failure is
        //    correct — do not silently merge or delete rows.
        // ----------------------------------------------------------------
        DB::statement(
            'DROP INDEX IF EXISTS uk_caess_group_location_scope'
        );

        DB::statement(
            'CREATE UNIQUE INDEX uk_caess_group_location
             ON cost_authority_enrollment_scope_snapshots (enrollment_group_id, location_id)'
        );

        // ----------------------------------------------------------------
        // B. Canonical scope trigger
        //
        //    On every INSERT or UPDATE on cost_authority_enrollment_scope_snapshots:
        //      1. Read property_id and item_id from the parent enrollment group.
        //      2. Calculate the canonical scope string.
        //      3. Reject the row if NEW.valuation_scope does not match.
        //
        //    Uses SQLSTATE 23514 (check_violation) for constraint rejection.
        //    Does not silently rewrite valuation_scope.
        //    Does not alter the existing parent-draft lifecycle trigger.
        // ----------------------------------------------------------------
        DB::statement("
            CREATE OR REPLACE FUNCTION guard_caess_canonical_scope()
            RETURNS TRIGGER AS \$\$
            DECLARE
                parent_property_id TEXT;
                parent_item_id     TEXT;
                expected_scope     TEXT;
            BEGIN
                SELECT property_id, item_id
                  INTO parent_property_id, parent_item_id
                  FROM cost_authority_enrollment_groups
                 WHERE id = NEW.enrollment_group_id;

                IF parent_property_id IS NULL THEN
                    RAISE EXCEPTION 'cost_authority_enrollment_scope_snapshots: parent enrollment group not found for id=%',
                        NEW.enrollment_group_id
                        USING ERRCODE = '23514';
                END IF;

                expected_scope := 'property:' || parent_property_id
                               || ':location:' || NEW.location_id
                               || ':item:' || parent_item_id;

                IF NEW.valuation_scope IS DISTINCT FROM expected_scope THEN
                    RAISE EXCEPTION
                        'cost_authority_enrollment_scope_snapshots: valuation_scope is not canonical. Expected %, got %',
                        expected_scope, NEW.valuation_scope
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_caess_canonical_scope
            BEFORE INSERT OR UPDATE ON cost_authority_enrollment_scope_snapshots
            FOR EACH ROW EXECUTE FUNCTION guard_caess_canonical_scope();
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // 1. Drop new canonical-scope trigger and function.
        DB::statement(
            'DROP TRIGGER IF EXISTS trg_caess_canonical_scope ON cost_authority_enrollment_scope_snapshots'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS guard_caess_canonical_scope()'
        );

        // 2. Drop the two-column unique index added in up().
        DB::statement(
            'DROP INDEX IF EXISTS uk_caess_group_location'
        );

        // 3. Recreate the original three-column unique index.
        DB::statement(
            'CREATE UNIQUE INDEX uk_caess_group_location_scope
             ON cost_authority_enrollment_scope_snapshots (enrollment_group_id, location_id, valuation_scope)'
        );
    }
};
