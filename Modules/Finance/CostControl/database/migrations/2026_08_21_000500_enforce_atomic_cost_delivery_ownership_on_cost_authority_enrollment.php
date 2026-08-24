<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_caeg_enrolled_initial_ownership()
            RETURNS trigger AS $$
            DECLARE
                ownership_count bigint;
                equivalent_count bigint;
            BEGIN
                SELECT count(*),
                       count(*) FILTER (WHERE
                           property_id = NEW.property_id
                           AND item_id = NEW.item_id
                           AND enrollment_group_id = NEW.id
                           AND delivery_mode = 'SYNCHRONOUS'
                           AND ownership_version = 1
                           AND activated_cutover_id IS NULL
                           AND established_by IS NOT NULL
                           AND btrim(established_by) <> ''
                           AND established_at IS NOT NULL
                           AND changed_by IS NULL
                           AND changed_at IS NULL
                       )
                  INTO ownership_count, equivalent_count
                  FROM cost_delivery_mode_ownerships
                 WHERE enrollment_group_id = NEW.id;

                IF ownership_count <> 1 OR equivalent_count <> 1 THEN
                    RAISE EXCEPTION
                        'cost_authority_enrollment_groups: ENROLLED commit requires exactly one equivalent initial SYNCHRONOUS ownership'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER trg_caeg_enrolled_initial_ownership
            AFTER INSERT OR UPDATE ON cost_authority_enrollment_groups
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            WHEN (NEW.status = 'enrolled')
            EXECUTE FUNCTION enforce_caeg_enrolled_initial_ownership();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'DROP TRIGGER IF EXISTS trg_caeg_enrolled_initial_ownership ON cost_authority_enrollment_groups'
        );
        DB::statement('DROP FUNCTION IF EXISTS enforce_caeg_enrolled_initial_ownership()');
    }
};
