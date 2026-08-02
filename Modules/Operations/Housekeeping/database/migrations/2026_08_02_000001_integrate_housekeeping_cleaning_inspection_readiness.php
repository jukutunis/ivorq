<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_tasks', function (Blueprint $table): void {
            $table->char('rework_source_inspection_id', 26)->nullable();
            $table->char('source_cleaning_task_id', 26)->nullable();
            $table->unique('rework_source_inspection_id', 'hk_cleaning_tasks_rework_inspection_unique');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE rooms ADD CONSTRAINT hk_rooms_id_property_unique UNIQUE (id, property_id)');
        DB::statement('ALTER TABLE cleaning_tasks ADD CONSTRAINT hk_cleaning_tasks_id_property_unique UNIQUE (id, property_id)');
        DB::statement('ALTER TABLE room_inspections ADD CONSTRAINT hk_room_inspections_id_property_unique UNIQUE (id, property_id)');

        DB::statement('ALTER TABLE cleaning_tasks ADD CONSTRAINT hk_cleaning_tasks_room_property_fk FOREIGN KEY (room_id, property_id) REFERENCES rooms (id, property_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE room_inspections ADD CONSTRAINT hk_room_inspections_room_property_fk FOREIGN KEY (room_id, property_id) REFERENCES rooms (id, property_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE room_inspections ADD CONSTRAINT hk_room_inspections_task_property_fk FOREIGN KEY (cleaning_task_id, property_id) REFERENCES cleaning_tasks (id, property_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE cleaning_tasks ADD CONSTRAINT hk_cleaning_tasks_rework_property_fk FOREIGN KEY (rework_source_inspection_id, property_id) REFERENCES room_inspections (id, property_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE cleaning_tasks ADD CONSTRAINT hk_cleaning_tasks_source_task_property_fk FOREIGN KEY (source_cleaning_task_id, property_id) REFERENCES cleaning_tasks (id, property_id) ON DELETE RESTRICT');

        DB::statement("CREATE UNIQUE INDEX hk_room_inspections_post_cleaning_task_unique ON room_inspections (cleaning_task_id) WHERE cleaning_task_id IS NOT NULL AND inspection_type = 'post_cleaning'");
        DB::statement("ALTER TABLE cleaning_tasks ADD CONSTRAINT hk_cleaning_tasks_rework_source_check CHECK ((rework_source_inspection_id IS NULL AND source_cleaning_task_id IS NULL) OR (rework_source_inspection_id IS NOT NULL AND source_cleaning_task_id IS NOT NULL AND task_type = 'checkout_cleaning'))");
        DB::statement('ALTER TABLE cleaning_tasks ADD CONSTRAINT hk_cleaning_tasks_source_not_self_check CHECK (source_cleaning_task_id IS NULL OR source_cleaning_task_id <> id)');
        DB::statement("ALTER TABLE housekeeping_room_readiness_transitions ADD CONSTRAINT hk_readiness_transition_type_check CHECK (transition_type IN ('START_CLEANING', 'SUBMIT_INSPECTION', 'RELEASE_READY', 'INSPECTION_FAILED', 'CHECKOUT_TURNOVER_INTAKE'))");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hk_room_inspections_lifecycle_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status IN ('passed', 'failed') OR OLD.inspection_type = 'post_cleaning' THEN
                        RAISE EXCEPTION 'Committed Room Inspection evidence is immutable.';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status IN ('passed', 'failed') THEN
                    RAISE EXCEPTION 'Terminal Room Inspection evidence is immutable.';
                END IF;

                IF OLD.inspection_type = 'post_cleaning' AND (
                    NEW.property_id IS DISTINCT FROM OLD.property_id
                    OR NEW.room_id IS DISTINCT FROM OLD.room_id
                    OR NEW.cleaning_task_id IS DISTINCT FROM OLD.cleaning_task_id
                    OR NEW.inspection_type IS DISTINCT FROM OLD.inspection_type
                    OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
                ) THEN
                    RAISE EXCEPTION 'Post-cleaning Room Inspection source evidence is immutable.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER hk_room_inspections_lifecycle_guard_trigger
            BEFORE UPDATE OR DELETE ON room_inspections
            FOR EACH ROW EXECUTE FUNCTION hk_room_inspections_lifecycle_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hk_cleaning_tasks_lifecycle_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'completed' OR OLD.rework_source_inspection_id IS NOT NULL THEN
                        RAISE EXCEPTION 'Committed Cleaning Task lifecycle evidence is immutable.';
                    END IF;
                    RETURN OLD;
                END IF;

                IF NEW.rework_source_inspection_id IS NOT NULL THEN
                    IF NEW.source_cleaning_task_id IS NULL
                       OR NEW.source_cleaning_task_id = NEW.id
                       OR (TG_OP = 'INSERT' AND NEW.status <> 'pending') THEN
                        RAISE EXCEPTION 'Re-cleaning task source evidence is invalid.';
                    END IF;

                    PERFORM 1
                    FROM room_inspections ri
                    JOIN cleaning_tasks source_task
                      ON source_task.id = ri.cleaning_task_id
                     AND source_task.property_id = ri.property_id
                     AND source_task.room_id = ri.room_id
                    WHERE ri.id = NEW.rework_source_inspection_id
                      AND ri.property_id = NEW.property_id
                      AND ri.room_id = NEW.room_id
                      AND ri.cleaning_task_id = NEW.source_cleaning_task_id
                      AND ri.inspection_type = 'post_cleaning'
                      AND ri.status = 'failed'
                      AND ri.deleted_at IS NULL
                      AND source_task.id = NEW.source_cleaning_task_id
                      AND source_task.property_id = NEW.property_id
                      AND source_task.room_id = NEW.room_id
                      AND source_task.task_type = 'checkout_cleaning'
                      AND source_task.status = 'completed'
                      AND source_task.deleted_at IS NULL;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Re-cleaning task must bind to the exact failed post-cleaning Inspection and completed checkout-cleaning source Task.';
                    END IF;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    RETURN NEW;
                END IF;

                IF OLD.status = 'completed' AND (
                    NEW.property_id IS DISTINCT FROM OLD.property_id
                    OR NEW.room_id IS DISTINCT FROM OLD.room_id
                    OR NEW.status IS DISTINCT FROM OLD.status
                    OR NEW.completed_at IS DISTINCT FROM OLD.completed_at
                    OR NEW.completed_by IS DISTINCT FROM OLD.completed_by
                    OR NEW.notes IS DISTINCT FROM OLD.notes
                    OR NEW.rework_source_inspection_id IS DISTINCT FROM OLD.rework_source_inspection_id
                    OR NEW.source_cleaning_task_id IS DISTINCT FROM OLD.source_cleaning_task_id
                    OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
                ) THEN
                    RAISE EXCEPTION 'Completed Cleaning Task lifecycle evidence is immutable.';
                END IF;

                IF NEW.verified_at IS DISTINCT FROM OLD.verified_at AND NOT (
                    OLD.status = 'completed'
                    AND OLD.verified_at IS NULL
                    AND NEW.verified_at IS NOT NULL
                    AND EXISTS (
                        SELECT 1
                        FROM room_inspections ri
                        WHERE ri.cleaning_task_id = OLD.id
                          AND ri.property_id = OLD.property_id
                          AND ri.room_id = OLD.room_id
                          AND ri.status = 'passed'
                    )
                ) THEN
                    RAISE EXCEPTION 'Cleaning Task verification requires a committed passed Inspection.';
                END IF;

                IF OLD.rework_source_inspection_id IS NOT NULL AND (
                    NEW.property_id IS DISTINCT FROM OLD.property_id
                    OR NEW.room_id IS DISTINCT FROM OLD.room_id
                    OR NEW.rework_source_inspection_id IS DISTINCT FROM OLD.rework_source_inspection_id
                    OR NEW.source_cleaning_task_id IS DISTINCT FROM OLD.source_cleaning_task_id
                ) THEN
                    RAISE EXCEPTION 'Re-cleaning source evidence is immutable.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER hk_cleaning_tasks_lifecycle_guard_trigger
            BEFORE INSERT OR UPDATE OR DELETE ON cleaning_tasks
            FOR EACH ROW EXECUTE FUNCTION hk_cleaning_tasks_lifecycle_guard()
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS hk_cleaning_tasks_lifecycle_guard_trigger ON cleaning_tasks');
            DB::statement('DROP FUNCTION IF EXISTS hk_cleaning_tasks_lifecycle_guard()');
            DB::statement('DROP TRIGGER IF EXISTS hk_room_inspections_lifecycle_guard_trigger ON room_inspections');
            DB::statement('DROP FUNCTION IF EXISTS hk_room_inspections_lifecycle_guard()');

            DB::statement('ALTER TABLE housekeeping_room_readiness_transitions DROP CONSTRAINT IF EXISTS hk_readiness_transition_type_check');
            DB::statement('ALTER TABLE cleaning_tasks DROP CONSTRAINT IF EXISTS hk_cleaning_tasks_source_not_self_check');
            DB::statement('ALTER TABLE cleaning_tasks DROP CONSTRAINT IF EXISTS hk_cleaning_tasks_rework_source_check');
            DB::statement('DROP INDEX IF EXISTS hk_room_inspections_post_cleaning_task_unique');

            DB::statement('ALTER TABLE cleaning_tasks DROP CONSTRAINT IF EXISTS hk_cleaning_tasks_source_task_property_fk');
            DB::statement('ALTER TABLE cleaning_tasks DROP CONSTRAINT IF EXISTS hk_cleaning_tasks_rework_property_fk');
            DB::statement('ALTER TABLE room_inspections DROP CONSTRAINT IF EXISTS hk_room_inspections_task_property_fk');
            DB::statement('ALTER TABLE room_inspections DROP CONSTRAINT IF EXISTS hk_room_inspections_room_property_fk');
            DB::statement('ALTER TABLE cleaning_tasks DROP CONSTRAINT IF EXISTS hk_cleaning_tasks_room_property_fk');

            DB::statement('ALTER TABLE room_inspections DROP CONSTRAINT IF EXISTS hk_room_inspections_id_property_unique');
            DB::statement('ALTER TABLE cleaning_tasks DROP CONSTRAINT IF EXISTS hk_cleaning_tasks_id_property_unique');
            DB::statement('ALTER TABLE rooms DROP CONSTRAINT IF EXISTS hk_rooms_id_property_unique');
        }

        Schema::table('cleaning_tasks', function (Blueprint $table): void {
            $table->dropUnique('hk_cleaning_tasks_rework_inspection_unique');
            $table->dropColumn(['rework_source_inspection_id', 'source_cleaning_task_id']);
        });
    }
};
