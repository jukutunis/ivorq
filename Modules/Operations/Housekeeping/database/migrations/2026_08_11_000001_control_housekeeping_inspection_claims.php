<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_inspections', function (Blueprint $table): void {
            $table->timestamp('claimed_at')->nullable();
            $table->string('claim_idempotency_key', 160)->nullable();
            $table->char('claim_source_hash', 64)->nullable();
            $table->smallInteger('claim_evidence_version')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Historical Package 13 rows remain unclaimed. Package 17 never
        // fabricates a key, hash, timestamp, version, or claimant for them.
        DB::statement(<<<'SQL'
            ALTER TABLE room_inspections
            ADD CONSTRAINT hk_p17_inspection_claim_evidence_check CHECK (
                (
                    claim_evidence_version IS NULL
                    AND claimed_at IS NULL
                    AND claim_idempotency_key IS NULL
                    AND claim_source_hash IS NULL
                )
                OR (
                    claim_evidence_version = 1
                    AND inspection_type = 'post_cleaning'
                    AND status IN ('in_progress', 'passed', 'failed')
                    AND supervisor_id IS NOT NULL
                    AND claimed_at IS NOT NULL
                    AND claim_idempotency_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{7,159}$'
                    AND claim_source_hash ~ '^[0-9a-f]{64}$'
                    AND deleted_at IS NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX hk_p17_inspection_claim_property_key_unique
            ON room_inspections (property_id, claim_idempotency_key)
            WHERE claim_idempotency_key IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hk_p17_inspection_claim_source_hash(
                evidence_version integer,
                source_property_id text,
                inspection_id text,
                source_room_id text,
                source_task_id text,
                completed_cleaner_id text,
                claimant_id text
            ) RETURNS text AS $$
                SELECT encode(sha256(convert_to(concat_ws(
                    '|',
                    'post_cleaning_inspection_claim',
                    evidence_version::text,
                    source_property_id,
                    inspection_id,
                    source_room_id,
                    source_task_id,
                    completed_cleaner_id,
                    claimant_id
                ), 'UTF8')), 'hex')
            $$ LANGUAGE sql IMMUTABLE STRICT
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hk_p17_inspection_claim_guard()
            RETURNS trigger AS $$
            DECLARE
                source_task cleaning_tasks%ROWTYPE;
                expected_hash text;
                completed_assignment_count integer;
                contradictory_assignment_count integer;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.claim_evidence_version = 1 THEN
                        RAISE EXCEPTION 'HK_P17_INSPECTION_CLAIM_IMMUTABLE';
                    END IF;
                    RETURN OLD;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.claim_evidence_version IS NOT NULL
                       OR NEW.claimed_at IS NOT NULL
                       OR NEW.claim_idempotency_key IS NOT NULL
                       OR NEW.claim_source_hash IS NOT NULL THEN
                        RAISE EXCEPTION 'HK_P17_INSPECTION_DIRECT_CLAIM_INSERT_PROHIBITED';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.claim_evidence_version = 1 AND (
                    NEW.property_id IS DISTINCT FROM OLD.property_id
                    OR NEW.room_id IS DISTINCT FROM OLD.room_id
                    OR NEW.cleaning_task_id IS DISTINCT FROM OLD.cleaning_task_id
                    OR NEW.inspection_type IS DISTINCT FROM OLD.inspection_type
                    OR NEW.supervisor_id IS DISTINCT FROM OLD.supervisor_id
                    OR NEW.claimed_at IS DISTINCT FROM OLD.claimed_at
                    OR NEW.claim_idempotency_key IS DISTINCT FROM OLD.claim_idempotency_key
                    OR NEW.claim_source_hash IS DISTINCT FROM OLD.claim_source_hash
                    OR NEW.claim_evidence_version IS DISTINCT FROM OLD.claim_evidence_version
                    OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
                ) THEN
                    RAISE EXCEPTION 'HK_P17_INSPECTION_CLAIM_IMMUTABLE';
                END IF;

                IF NEW.claim_evidence_version IS NULL THEN
                    IF NEW.claimed_at IS NOT NULL
                       OR NEW.claim_idempotency_key IS NOT NULL
                       OR NEW.claim_source_hash IS NOT NULL THEN
                        RAISE EXCEPTION 'HK_P17_INSPECTION_CLAIM_INCOHERENT';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.claim_evidence_version <> 1
                   OR NEW.inspection_type <> 'post_cleaning'
                   OR NEW.status NOT IN ('in_progress', 'passed', 'failed')
                   OR NEW.supervisor_id IS NULL
                   OR NEW.claimed_at IS NULL
                   OR NEW.claim_idempotency_key IS NULL
                   OR NEW.claim_source_hash IS NULL
                   OR NEW.deleted_at IS NOT NULL THEN
                    RAISE EXCEPTION 'HK_P17_INSPECTION_CLAIM_INCOHERENT';
                END IF;

                IF OLD.claim_evidence_version IS NULL
                   AND NEW.claim_evidence_version = 1
                   AND (
                    OLD.status <> 'pending'
                    OR OLD.supervisor_id IS NOT NULL
                    OR OLD.claimed_at IS NOT NULL
                    OR OLD.claim_idempotency_key IS NOT NULL
                    OR OLD.claim_source_hash IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'HK_P17_INSPECTION_LEGACY_ADOPTION_PROHIBITED';
                END IF;

                IF OLD.claim_evidence_version IS NULL
                   AND NEW.claim_evidence_version = 1
                   AND NEW.status <> 'in_progress' THEN
                    RAISE EXCEPTION 'HK_P17_INSPECTION_CLAIM_INITIAL_STATUS_INVALID';
                END IF;

                SELECT task.* INTO source_task
                FROM cleaning_tasks task
                JOIN rooms room
                  ON room.id = task.room_id
                 AND room.property_id = task.property_id
                WHERE task.id = NEW.cleaning_task_id
                  AND task.property_id = NEW.property_id
                  AND task.room_id = NEW.room_id
                  AND task.task_type = 'checkout_cleaning'
                  AND task.status = 'completed'
                  AND task.completed_by IS NOT NULL
                  AND task.deleted_at IS NULL
                  AND room.id = NEW.room_id
                  AND room.property_id = NEW.property_id
                  AND room.is_active = true
                  AND room.deleted_at IS NULL;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'HK_P17_INSPECTION_CLAIM_SOURCE_CONFLICT';
                END IF;

                IF NEW.supervisor_id = source_task.completed_by THEN
                    RAISE EXCEPTION 'HK_P17_INSPECTION_CLAIM_CLEANER_PROHIBITED';
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM users claimant
                    JOIN property_user membership
                      ON membership.user_id = claimant.id
                     AND membership.property_id = NEW.property_id
                     AND membership.status = 'active'
                    WHERE claimant.id = NEW.supervisor_id
                      AND claimant.is_active = true
                      AND claimant.deleted_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'HK_P17_INSPECTION_CLAIMANT_INVALID';
                END IF;

                SELECT
                    COUNT(*),
                    COUNT(*) FILTER (WHERE assignment.user_id IS DISTINCT FROM source_task.completed_by
                        OR assignment.attendant_id IS DISTINCT FROM source_task.completed_by
                        OR assignment.closed_by IS DISTINCT FROM source_task.completed_by
                        OR assignment.closed_at IS NULL
                        OR assignment.completed_at IS NULL)
                INTO completed_assignment_count, contradictory_assignment_count
                FROM housekeeping_task_assignments assignment
                WHERE assignment.property_id = NEW.property_id
                  AND assignment.cleaning_task_id = NEW.cleaning_task_id
                  AND assignment.status = 'completed'
                  AND assignment.deleted_at IS NULL;

                IF completed_assignment_count > 1 OR contradictory_assignment_count > 0 THEN
                    RAISE EXCEPTION 'HK_P17_INSPECTION_CLAIM_ASSIGNMENT_CONFLICT';
                END IF;

                expected_hash := hk_p17_inspection_claim_source_hash(
                    NEW.claim_evidence_version,
                    NEW.property_id::text,
                    NEW.id::text,
                    NEW.room_id::text,
                    NEW.cleaning_task_id::text,
                    source_task.completed_by::text,
                    NEW.supervisor_id::text
                );
                IF NEW.claim_source_hash IS DISTINCT FROM expected_hash THEN
                    RAISE EXCEPTION 'HK_P17_INSPECTION_CLAIM_HASH_INVALID';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER hk_p17_inspection_claim_guard_trigger
            BEFORE INSERT OR UPDATE OR DELETE ON room_inspections
            FOR EACH ROW EXECUTE FUNCTION hk_p17_inspection_claim_guard()
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS hk_p17_inspection_claim_guard_trigger ON room_inspections');
            DB::statement('DROP FUNCTION IF EXISTS hk_p17_inspection_claim_guard()');
            DB::statement('DROP FUNCTION IF EXISTS hk_p17_inspection_claim_source_hash(integer, text, text, text, text, text, text)');
            DB::statement('DROP INDEX IF EXISTS hk_p17_inspection_claim_property_key_unique');
            DB::statement('ALTER TABLE room_inspections DROP CONSTRAINT IF EXISTS hk_p17_inspection_claim_evidence_check');
        }

        Schema::table('room_inspections', function (Blueprint $table): void {
            $table->dropColumn([
                'claimed_at',
                'claim_idempotency_key',
                'claim_source_hash',
                'claim_evidence_version',
            ]);
        });
    }
};
