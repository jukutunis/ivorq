<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('housekeeping_task_assignments', function (Blueprint $table): void {
            $table->char('assigned_by', 26)->nullable();
            $table->string('assignment_action', 30)->nullable();
            $table->string('idempotency_key', 160)->nullable();
            $table->char('source_hash', 64)->nullable();
            $table->string('evidence_version', 60)->default('housekeeping-assignment-legacy-v0');
            $table->char('previous_assignment_id', 26)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->char('closed_by', 26)->nullable();
            $table->text('closure_reason')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM housekeeping_task_assignments a
                    LEFT JOIN cleaning_tasks t ON t.id = a.cleaning_task_id
                    WHERE t.id IS NULL
                       OR (a.property_id IS NOT NULL AND a.property_id IS DISTINCT FROM t.property_id)
                       OR (a.user_id IS NOT NULL AND a.attendant_id IS NOT NULL AND a.user_id IS DISTINCT FROM a.attendant_id)
                       OR a.status NOT IN ('active', 'completed', 'cancelled')
                       OR (a.user_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = a.user_id))
                       OR (a.attendant_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = a.attendant_id))
                       OR (a.department_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM departments d WHERE d.id = a.department_id))
                ) OR EXISTS (
                    SELECT 1
                    FROM housekeeping_task_assignments
                    WHERE status = 'active' AND deleted_at IS NULL
                    GROUP BY cleaning_task_id
                    HAVING COUNT(*) > 1
                ) OR EXISTS (
                    SELECT 1
                    FROM cleaning_tasks t
                    LEFT JOIN housekeeping_task_assignments a
                      ON a.cleaning_task_id = t.id
                     AND a.status = 'active'
                     AND a.deleted_at IS NULL
                    WHERE t.deleted_at IS NULL
                    GROUP BY t.id, t.status
                    HAVING (t.status = 'pending' AND COUNT(a.id) <> 0)
                       OR (t.status IN ('assigned', 'in_progress') AND COUNT(a.id) <> 1)
                       OR (t.status IN ('completed', 'cancelled') AND COUNT(a.id) <> 0)
                ) THEN
                    RAISE EXCEPTION 'HK_P15_SOURCE_CONFLICT';
                END IF;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            UPDATE housekeeping_task_assignments a
            SET property_id = t.property_id
            FROM cleaning_tasks t
            WHERE t.id = a.cleaning_task_id
              AND a.property_id IS NULL
        SQL);
        DB::statement('UPDATE housekeeping_task_assignments SET user_id = attendant_id WHERE user_id IS NULL AND attendant_id IS NOT NULL');
        DB::statement('UPDATE housekeeping_task_assignments SET attendant_id = user_id WHERE attendant_id IS NULL AND user_id IS NOT NULL');
        DB::statement("ALTER TABLE housekeeping_task_assignments ALTER COLUMN evidence_version SET DEFAULT 'housekeeping-assignment-v1'");
        DB::statement('ALTER TABLE housekeeping_task_assignments ALTER COLUMN property_id SET NOT NULL');

        DB::statement("ALTER TABLE housekeeping_task_assignments ADD CONSTRAINT hk_p15_assignment_status_check CHECK (status IN ('active', 'completed', 'cancelled'))");
        DB::statement("ALTER TABLE housekeeping_task_assignments ADD CONSTRAINT hk_p15_assignment_action_check CHECK (assignment_action IS NULL OR assignment_action IN ('initial', 'reassignment'))");
        DB::statement('ALTER TABLE housekeeping_task_assignments ADD CONSTRAINT hk_p15_assignment_user_mirror_check CHECK (user_id IS NULL OR attendant_id IS NULL OR user_id = attendant_id)');
        DB::statement('ALTER TABLE housekeeping_task_assignments ADD CONSTRAINT hk_p15_assignment_previous_not_self_check CHECK (previous_assignment_id IS NULL OR previous_assignment_id <> id)');
        DB::statement("ALTER TABLE housekeeping_task_assignments ADD CONSTRAINT hk_p15_assignment_canonical_evidence_check CHECK (evidence_version <> 'housekeeping-assignment-v1' OR (assignment_action IS NOT NULL AND assigned_by IS NOT NULL AND user_id IS NOT NULL AND attendant_id IS NOT NULL AND department_id IS NOT NULL AND assigned_at IS NOT NULL AND idempotency_key IS NOT NULL AND btrim(idempotency_key) <> '' AND source_hash ~ '^[0-9a-f]{64}$' AND deleted_at IS NULL))");
        DB::statement("ALTER TABLE housekeeping_task_assignments ADD CONSTRAINT hk_p15_assignment_previous_action_check CHECK ((assignment_action IS NULL) OR (assignment_action = 'initial' AND previous_assignment_id IS NULL) OR (assignment_action = 'reassignment' AND previous_assignment_id IS NOT NULL))");
        DB::statement('ALTER TABLE housekeeping_task_assignments ADD CONSTRAINT hk_p15_assignment_task_property_fk FOREIGN KEY (cleaning_task_id, property_id) REFERENCES cleaning_tasks (id, property_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE housekeeping_task_assignments ADD CONSTRAINT hk_p15_assignment_previous_fk FOREIGN KEY (previous_assignment_id) REFERENCES housekeeping_task_assignments (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE housekeeping_task_assignments ADD CONSTRAINT hk_p15_assignment_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE housekeeping_task_assignments ADD CONSTRAINT hk_p15_assignment_attendant_fk FOREIGN KEY (attendant_id) REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE housekeeping_task_assignments ADD CONSTRAINT hk_p15_assignment_department_fk FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE housekeeping_task_assignments ADD CONSTRAINT hk_p15_assignment_assigned_by_fk FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE housekeeping_task_assignments ADD CONSTRAINT hk_p15_assignment_closed_by_fk FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE RESTRICT');

        DB::statement("CREATE UNIQUE INDEX hk_p15_assignment_one_active_per_task ON housekeeping_task_assignments (cleaning_task_id) WHERE status = 'active' AND deleted_at IS NULL");
        DB::statement("CREATE UNIQUE INDEX hk_p15_assignment_property_idempotency_unique ON housekeeping_task_assignments (property_id, idempotency_key) WHERE idempotency_key IS NOT NULL");
        DB::statement('CREATE INDEX hk_p15_assignment_property_status_idx ON housekeeping_task_assignments (property_id, status)');
        DB::statement('CREATE INDEX hk_p15_assignment_task_status_idx ON housekeeping_task_assignments (cleaning_task_id, status)');
        DB::statement('CREATE INDEX hk_p15_assignment_user_status_idx ON housekeeping_task_assignments (user_id, status)');
        DB::statement('CREATE INDEX hk_p15_assignment_previous_idx ON housekeeping_task_assignments (previous_assignment_id)');
        DB::statement('CREATE INDEX hk_p15_assignment_assigned_at_idx ON housekeeping_task_assignments (assigned_at, id)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hk_p15_assignment_relationship_guard()
            RETURNS trigger AS $$
            DECLARE
                task_room_id char(26);
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RETURN NEW;
                END IF;

                IF NEW.evidence_version IS DISTINCT FROM 'housekeeping-assignment-v1' THEN
                    RAISE EXCEPTION 'HK_P15_ASSIGNMENT_INVALID';
                END IF;

                IF NEW.status <> 'active'
                   OR NEW.accepted_at IS NOT NULL
                   OR NEW.assigned_at IS NULL
                   OR NEW.assigned_by IS NULL
                   OR NEW.idempotency_key IS NULL
                   OR btrim(NEW.idempotency_key) = ''
                   OR NEW.source_hash IS NULL THEN
                    RAISE EXCEPTION 'HK_P15_ASSIGNMENT_INVALID';
                END IF;

                SELECT t.room_id INTO task_room_id
                FROM cleaning_tasks t
                JOIN properties p ON p.id = t.property_id
                JOIN rooms r ON r.id = t.room_id AND r.property_id = t.property_id
                WHERE t.id = NEW.cleaning_task_id
                  AND t.property_id = NEW.property_id
                  AND t.deleted_at IS NULL
                  AND p.is_active = true
                  AND p.deleted_at IS NULL
                  AND r.is_active = true
                  AND r.deleted_at IS NULL;
                IF NOT FOUND OR task_room_id IS NULL THEN
                    RAISE EXCEPTION 'HK_P15_ASSIGNMENT_RELATIONSHIP_INVALID';
                END IF;

                IF NEW.user_id IS NULL OR NEW.attendant_id IS NULL OR NEW.user_id <> NEW.attendant_id OR NOT EXISTS (
                    SELECT 1
                    FROM users u
                    JOIN property_user pu ON pu.user_id = u.id
                    JOIN departments d ON d.id = NEW.department_id
                    WHERE u.id = NEW.user_id
                      AND u.is_active = true
                      AND u.deleted_at IS NULL
                      AND pu.property_id = NEW.property_id
                      AND pu.status = 'active'
                      AND d.property_id = NEW.property_id
                      AND d.is_active = true
                      AND d.deleted_at IS NULL
                      AND u.department_id = d.id
                ) THEN
                    RAISE EXCEPTION 'HK_P15_ASSIGNMENT_TARGET_INVALID';
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM users actor
                    WHERE actor.id = NEW.assigned_by
                      AND actor.is_active = true
                      AND actor.deleted_at IS NULL
                      AND (
                          actor.is_system_admin = true
                          OR EXISTS (
                              SELECT 1 FROM property_user actor_membership
                              WHERE actor_membership.user_id = actor.id
                                AND actor_membership.property_id = NEW.property_id
                                AND actor_membership.status = 'active'
                          )
                      )
                ) THEN
                    RAISE EXCEPTION 'HK_P15_ASSIGNMENT_ACTOR_INVALID';
                END IF;

                IF NEW.assignment_action = 'initial' AND NEW.previous_assignment_id IS NOT NULL THEN
                    RAISE EXCEPTION 'HK_P15_ASSIGNMENT_PREVIOUS_INVALID';
                END IF;
                IF NEW.assignment_action = 'reassignment' AND NOT EXISTS (
                    SELECT 1
                    FROM housekeeping_task_assignments previous
                    WHERE previous.id = NEW.previous_assignment_id
                      AND previous.id <> NEW.id
                      AND previous.cleaning_task_id = NEW.cleaning_task_id
                      AND previous.property_id = NEW.property_id
                      AND previous.status = 'cancelled'
                      AND previous.closure_reason = 'reassigned'
                      AND previous.deleted_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'HK_P15_ASSIGNMENT_PREVIOUS_INVALID';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER hk_p15_assignment_relationship_guard_trigger
            BEFORE INSERT OR UPDATE ON housekeeping_task_assignments
            FOR EACH ROW EXECUTE FUNCTION hk_p15_assignment_relationship_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hk_p15_assignment_immutability_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'HK_P15_ASSIGNMENT_IMMUTABLE';
                END IF;

                IF NEW.deleted_at IS DISTINCT FROM OLD.deleted_at THEN
                    RAISE EXCEPTION 'HK_P15_ASSIGNMENT_IMMUTABLE';
                END IF;

                IF OLD.status <> 'active' THEN
                    IF NEW IS DISTINCT FROM OLD THEN
                        RAISE EXCEPTION 'HK_P15_ASSIGNMENT_TERMINAL';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.property_id IS DISTINCT FROM OLD.property_id
                   OR NEW.cleaning_task_id IS DISTINCT FROM OLD.cleaning_task_id
                   OR NEW.user_id IS DISTINCT FROM OLD.user_id
                   OR NEW.attendant_id IS DISTINCT FROM OLD.attendant_id
                   OR NEW.department_id IS DISTINCT FROM OLD.department_id
                   OR NEW.assigned_by IS DISTINCT FROM OLD.assigned_by
                   OR NEW.assigned_at IS DISTINCT FROM OLD.assigned_at
                   OR NEW.assignment_action IS DISTINCT FROM OLD.assignment_action
                   OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                   OR NEW.source_hash IS DISTINCT FROM OLD.source_hash
                   OR NEW.evidence_version IS DISTINCT FROM OLD.evidence_version
                   OR NEW.previous_assignment_id IS DISTINCT FROM OLD.previous_assignment_id
                   OR NEW.accepted_at IS DISTINCT FROM OLD.accepted_at THEN
                    RAISE EXCEPTION 'HK_P15_ASSIGNMENT_IMMUTABLE';
                END IF;

                IF NEW.status NOT IN ('completed', 'cancelled')
                   OR NEW.closed_at IS NULL
                   OR NEW.closed_by IS NULL
                   OR NEW.closure_reason IS NULL
                   OR btrim(NEW.closure_reason) = ''
                   OR (NEW.status = 'completed' AND NEW.completed_at IS NULL)
                   OR (NEW.status <> 'completed' AND NEW.completed_at IS NOT NULL) THEN
                    RAISE EXCEPTION 'HK_P15_ASSIGNMENT_CLOSURE_INVALID';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER hk_p15_assignment_immutability_guard_trigger
            BEFORE UPDATE OR DELETE ON housekeeping_task_assignments
            FOR EACH ROW EXECUTE FUNCTION hk_p15_assignment_immutability_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hk_p15_task_assignment_consistency_guard()
            RETURNS trigger AS $$
            DECLARE
                task_id char(26);
                task_status text;
                active_count integer;
            BEGIN
                IF TG_TABLE_NAME = 'cleaning_tasks' THEN
                    task_id := COALESCE(NEW.id, OLD.id);
                ELSE
                    task_id := COALESCE(NEW.cleaning_task_id, OLD.cleaning_task_id);
                END IF;

                SELECT status INTO task_status FROM cleaning_tasks WHERE id = task_id AND deleted_at IS NULL;
                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT COUNT(*) INTO active_count
                FROM housekeeping_task_assignments
                WHERE cleaning_task_id = task_id AND status = 'active' AND deleted_at IS NULL;

                IF (task_status = 'pending' AND active_count <> 0)
                   OR (task_status IN ('assigned', 'in_progress') AND active_count <> 1)
                   OR (task_status IN ('completed', 'cancelled') AND active_count <> 0)
                   OR task_status NOT IN ('pending', 'assigned', 'in_progress', 'completed', 'cancelled') THEN
                    RAISE EXCEPTION 'HK_P15_TASK_ASSIGNMENT_CONFLICT';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER hk_p15_assignment_task_consistency_trigger
            AFTER INSERT OR UPDATE OR DELETE ON housekeeping_task_assignments
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION hk_p15_task_assignment_consistency_guard()
        SQL);
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER hk_p15_task_assignment_consistency_trigger
            AFTER INSERT OR UPDATE OR DELETE ON cleaning_tasks
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION hk_p15_task_assignment_consistency_guard()
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS hk_p15_task_assignment_consistency_trigger ON cleaning_tasks');
            DB::statement('DROP TRIGGER IF EXISTS hk_p15_assignment_task_consistency_trigger ON housekeeping_task_assignments');
            DB::statement('DROP FUNCTION IF EXISTS hk_p15_task_assignment_consistency_guard()');
            DB::statement('DROP TRIGGER IF EXISTS hk_p15_assignment_immutability_guard_trigger ON housekeeping_task_assignments');
            DB::statement('DROP FUNCTION IF EXISTS hk_p15_assignment_immutability_guard()');
            DB::statement('DROP TRIGGER IF EXISTS hk_p15_assignment_relationship_guard_trigger ON housekeeping_task_assignments');
            DB::statement('DROP FUNCTION IF EXISTS hk_p15_assignment_relationship_guard()');

            DB::statement('DROP INDEX IF EXISTS hk_p15_assignment_assigned_at_idx');
            DB::statement('DROP INDEX IF EXISTS hk_p15_assignment_previous_idx');
            DB::statement('DROP INDEX IF EXISTS hk_p15_assignment_user_status_idx');
            DB::statement('DROP INDEX IF EXISTS hk_p15_assignment_task_status_idx');
            DB::statement('DROP INDEX IF EXISTS hk_p15_assignment_property_status_idx');
            DB::statement('DROP INDEX IF EXISTS hk_p15_assignment_property_idempotency_unique');
            DB::statement('DROP INDEX IF EXISTS hk_p15_assignment_one_active_per_task');

            DB::statement('ALTER TABLE housekeeping_task_assignments DROP CONSTRAINT IF EXISTS hk_p15_assignment_previous_fk');
            DB::statement('ALTER TABLE housekeeping_task_assignments DROP CONSTRAINT IF EXISTS hk_p15_assignment_task_property_fk');
            DB::statement('ALTER TABLE housekeeping_task_assignments DROP CONSTRAINT IF EXISTS hk_p15_assignment_closed_by_fk');
            DB::statement('ALTER TABLE housekeeping_task_assignments DROP CONSTRAINT IF EXISTS hk_p15_assignment_assigned_by_fk');
            DB::statement('ALTER TABLE housekeeping_task_assignments DROP CONSTRAINT IF EXISTS hk_p15_assignment_department_fk');
            DB::statement('ALTER TABLE housekeeping_task_assignments DROP CONSTRAINT IF EXISTS hk_p15_assignment_attendant_fk');
            DB::statement('ALTER TABLE housekeeping_task_assignments DROP CONSTRAINT IF EXISTS hk_p15_assignment_user_fk');
            DB::statement('ALTER TABLE housekeeping_task_assignments DROP CONSTRAINT IF EXISTS hk_p15_assignment_previous_action_check');
            DB::statement('ALTER TABLE housekeeping_task_assignments DROP CONSTRAINT IF EXISTS hk_p15_assignment_canonical_evidence_check');
            DB::statement('ALTER TABLE housekeeping_task_assignments DROP CONSTRAINT IF EXISTS hk_p15_assignment_previous_not_self_check');
            DB::statement('ALTER TABLE housekeeping_task_assignments DROP CONSTRAINT IF EXISTS hk_p15_assignment_user_mirror_check');
            DB::statement('ALTER TABLE housekeeping_task_assignments DROP CONSTRAINT IF EXISTS hk_p15_assignment_action_check');
            DB::statement('ALTER TABLE housekeeping_task_assignments DROP CONSTRAINT IF EXISTS hk_p15_assignment_status_check');
            DB::statement('ALTER TABLE housekeeping_task_assignments ALTER COLUMN property_id DROP NOT NULL');
        }

        Schema::table('housekeeping_task_assignments', function (Blueprint $table): void {
            $table->dropColumn([
                'assigned_by',
                'assignment_action',
                'idempotency_key',
                'source_hash',
                'evidence_version',
                'previous_assignment_id',
                'closed_at',
                'closed_by',
                'closure_reason',
            ]);
        });
    }
};
