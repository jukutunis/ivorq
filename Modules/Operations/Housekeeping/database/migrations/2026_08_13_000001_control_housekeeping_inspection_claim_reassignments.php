<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_inspection_claim_reassignments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('room_inspection_id', 26);
            $table->char('original_claimant_id', 26);
            $table->char('replacement_claimant_id', 26);
            $table->char('intervened_by', 26);
            $table->string('original_ineligibility_code', 80);
            $table->string('reason', 1000);
            $table->string('idempotency_key', 160);
            $table->char('source_hash', 64);
            $table->smallInteger('evidence_version');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->foreign('property_id', 'hk_p19_reassignment_property_fk')
                ->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('room_inspection_id', 'hk_p19_reassignment_inspection_fk')
                ->references('id')->on('room_inspections')->restrictOnDelete();
            $table->foreign('replacement_claimant_id', 'hk_p19_reassignment_replacement_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('intervened_by', 'hk_p19_reassignment_intervenor_fk')
                ->references('id')->on('users')->restrictOnDelete();

            $table->unique(['property_id', 'room_inspection_id'], 'hk_p19_reassignment_property_inspection_unique');
            $table->unique(['property_id', 'idempotency_key'], 'hk_p19_reassignment_property_key_unique');
            $table->index('room_inspection_id', 'hk_p19_reassignment_inspection_idx');
            $table->index('original_claimant_id', 'hk_p19_reassignment_original_idx');
            $table->index('replacement_claimant_id', 'hk_p19_reassignment_replacement_idx');
            $table->index('intervened_by', 'hk_p19_reassignment_intervenor_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE housekeeping_inspection_claim_reassignments
            ADD CONSTRAINT hk_p19_reassignment_shape_check CHECK (
                evidence_version = 1
                AND original_claimant_id <> replacement_claimant_id
                AND reason = regexp_replace(btrim(reason), '\s+', ' ', 'g')
                AND reason <> ''
                AND idempotency_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{7,159}$'
                AND source_hash ~ '^[0-9a-f]{64}$'
                AND original_ineligibility_code IN (
                    'original_user_inactive_or_deleted',
                    'original_property_membership_inactive_or_missing',
                    'original_conduct_permission_missing'
                )
                AND occurred_at = created_at
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hk_p19_user_has_property_permission(
                source_user_id text,
                source_property_id text,
                permission_name text
            ) RETURNS boolean AS $$
                SELECT EXISTS (
                    SELECT 1
                    FROM permissions permission
                    JOIN model_has_permissions direct_permission
                      ON direct_permission.permission_id = permission.id
                    WHERE permission.name = permission_name
                      AND permission.guard_name = 'web'
                      AND direct_permission.model_id::text = source_user_id
                      AND direct_permission.model_type = 'Modules\Foundation\User\Models\User'
                      AND (direct_permission.property_id::text = source_property_id OR direct_permission.property_id IS NULL)
                ) OR EXISTS (
                    SELECT 1
                    FROM permissions permission
                    JOIN role_has_permissions role_permission
                      ON role_permission.permission_id = permission.id
                    JOIN model_has_roles assigned_role
                      ON assigned_role.role_id = role_permission.role_id
                    WHERE permission.name = permission_name
                      AND permission.guard_name = 'web'
                      AND assigned_role.model_id::text = source_user_id
                      AND assigned_role.model_type = 'Modules\Foundation\User\Models\User'
                      AND (assigned_role.property_id::text = source_property_id OR assigned_role.property_id IS NULL)
                )
            $$ LANGUAGE sql STABLE STRICT
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hk_p19_inspection_claim_reassignment_source_hash(
                recovery_evidence_version integer,
                source_property_id text,
                source_inspection_id text,
                original_supervisor_id text,
                original_claimed_at text,
                original_claim_idempotency_key text,
                original_claim_source_hash text,
                original_claim_evidence_version integer,
                source_cleaning_task_id text,
                completed_cleaner_id text,
                source_original_claimant_id text,
                source_replacement_claimant_id text,
                source_intervened_by text,
                source_ineligibility_code text,
                normalized_reason text,
                recovery_idempotency_key text
            ) RETURNS text AS $$
                SELECT encode(sha256(convert_to(concat_ws(
                    '|',
                    'housekeeping_inspection_claim_reassignment',
                    recovery_evidence_version::text,
                    source_property_id,
                    source_inspection_id,
                    original_supervisor_id,
                    original_claimed_at,
                    original_claim_idempotency_key,
                    original_claim_source_hash,
                    original_claim_evidence_version::text,
                    source_cleaning_task_id,
                    completed_cleaner_id,
                    source_original_claimant_id,
                    source_replacement_claimant_id,
                    source_intervened_by,
                    source_ineligibility_code,
                    normalized_reason,
                    recovery_idempotency_key
                ), 'UTF8')), 'hex')
            $$ LANGUAGE sql IMMUTABLE STRICT
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hk_p19_inspection_claim_reassignment_insert_guard()
            RETURNS trigger AS $$
            DECLARE
                source_inspection room_inspections%ROWTYPE;
                source_task cleaning_tasks%ROWTYPE;
                original_user users%ROWTYPE;
                replacement_user users%ROWTYPE;
                intervenor_user users%ROWTYPE;
                original_exists boolean;
                completed_assignment_count integer;
                contradictory_assignment_count integer;
                expected_ineligibility_code text;
                expected_hash text;
            BEGIN
                SELECT inspection.* INTO source_inspection
                FROM room_inspections inspection
                JOIN rooms room
                  ON room.id = inspection.room_id
                 AND room.property_id = inspection.property_id
                 AND room.is_active = true
                 AND room.deleted_at IS NULL
                WHERE inspection.id = NEW.room_inspection_id
                  AND inspection.property_id = NEW.property_id
                  AND inspection.inspection_type = 'post_cleaning'
                  AND inspection.status = 'in_progress'
                  AND inspection.claim_evidence_version = 1
                  AND inspection.supervisor_id IS NOT NULL
                  AND inspection.claimed_at IS NOT NULL
                  AND inspection.claim_idempotency_key IS NOT NULL
                  AND inspection.claim_source_hash ~ '^[0-9a-f]{64}$'
                  AND inspection.deleted_at IS NULL
                FOR SHARE OF inspection;
                IF NOT FOUND OR source_inspection.supervisor_id IS DISTINCT FROM NEW.original_claimant_id THEN
                    RAISE EXCEPTION 'HK_P19_INSPECTION_CLAIM_RECOVERY_SOURCE_CONFLICT';
                END IF;

                SELECT task.* INTO source_task
                FROM cleaning_tasks task
                WHERE task.id = source_inspection.cleaning_task_id
                  AND task.property_id = NEW.property_id
                  AND task.room_id = source_inspection.room_id
                  AND task.task_type = 'checkout_cleaning'
                  AND task.status = 'completed'
                  AND task.completed_by IS NOT NULL
                  AND task.deleted_at IS NULL
                FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'HK_P19_INSPECTION_CLAIM_RECOVERY_SOURCE_CONFLICT';
                END IF;

                IF source_task.completed_by = NEW.replacement_claimant_id THEN
                    RAISE EXCEPTION 'HK_P19_INSPECTION_CLAIM_RECOVERY_REPLACEMENT_CLEANER_PROHIBITED';
                END IF;
                IF NEW.original_claimant_id = NEW.replacement_claimant_id THEN
                    RAISE EXCEPTION 'HK_P19_INSPECTION_CLAIM_RECOVERY_REPLACEMENT_ORIGINAL_PROHIBITED';
                END IF;

                IF source_inspection.claim_source_hash IS DISTINCT FROM hk_p17_inspection_claim_source_hash(
                    source_inspection.claim_evidence_version,
                    source_inspection.property_id::text,
                    source_inspection.id::text,
                    source_inspection.room_id::text,
                    source_inspection.cleaning_task_id::text,
                    source_task.completed_by::text,
                    source_inspection.supervisor_id::text
                ) THEN
                    RAISE EXCEPTION 'HK_P19_INSPECTION_CLAIM_RECOVERY_P17_SOURCE_INVALID';
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
                  AND assignment.cleaning_task_id = source_task.id
                  AND assignment.status = 'completed'
                  AND assignment.deleted_at IS NULL;
                IF completed_assignment_count > 1 OR contradictory_assignment_count > 0 THEN
                    RAISE EXCEPTION 'HK_P19_INSPECTION_CLAIM_RECOVERY_ASSIGNMENT_CONFLICT';
                END IF;

                SELECT * INTO original_user FROM users WHERE id = NEW.original_claimant_id;
                original_exists := FOUND;
                IF NOT original_exists OR original_user.is_active IS DISTINCT FROM true OR original_user.deleted_at IS NOT NULL THEN
                    expected_ineligibility_code := 'original_user_inactive_or_deleted';
                ELSIF NOT EXISTS (
                    SELECT 1 FROM property_user membership
                    WHERE membership.property_id = NEW.property_id
                      AND membership.user_id = NEW.original_claimant_id
                      AND membership.status = 'active'
                ) THEN
                    expected_ineligibility_code := 'original_property_membership_inactive_or_missing';
                ELSIF NOT hk_p19_user_has_property_permission(
                    NEW.original_claimant_id::text,
                    NEW.property_id::text,
                    'housekeeping.inspection.conduct'
                ) THEN
                    expected_ineligibility_code := 'original_conduct_permission_missing';
                ELSE
                    RAISE EXCEPTION 'HK_INSPECTION_CLAIM_RECOVERY_ORIGINAL_STILL_ELIGIBLE';
                END IF;
                IF NEW.original_ineligibility_code IS DISTINCT FROM expected_ineligibility_code THEN
                    RAISE EXCEPTION 'HK_P19_INSPECTION_CLAIM_RECOVERY_INELIGIBILITY_INVALID';
                END IF;

                SELECT * INTO replacement_user FROM users WHERE id = NEW.replacement_claimant_id;
                IF NOT FOUND
                   OR replacement_user.is_active IS DISTINCT FROM true
                   OR replacement_user.deleted_at IS NOT NULL
                   OR NOT EXISTS (
                        SELECT 1 FROM property_user membership
                        WHERE membership.property_id = NEW.property_id
                          AND membership.user_id = NEW.replacement_claimant_id
                          AND membership.status = 'active'
                   )
                   OR NOT hk_p19_user_has_property_permission(
                        NEW.replacement_claimant_id::text,
                        NEW.property_id::text,
                        'housekeeping.inspection.conduct'
                   ) THEN
                    RAISE EXCEPTION 'HK_P19_INSPECTION_CLAIM_RECOVERY_REPLACEMENT_INVALID';
                END IF;

                SELECT * INTO intervenor_user FROM users WHERE id = NEW.intervened_by;
                IF NOT FOUND
                   OR intervenor_user.is_active IS DISTINCT FROM true
                   OR intervenor_user.deleted_at IS NOT NULL
                   OR NOT EXISTS (
                        SELECT 1 FROM property_user membership
                        WHERE membership.property_id = NEW.property_id
                          AND membership.user_id = NEW.intervened_by
                          AND membership.status = 'active'
                   )
                   OR NOT hk_p19_user_has_property_permission(
                        NEW.intervened_by::text,
                        NEW.property_id::text,
                        'housekeeping.inspection.approve'
                   ) THEN
                    RAISE EXCEPTION 'HK_P19_INSPECTION_CLAIM_RECOVERY_INTERVENOR_INVALID';
                END IF;

                expected_hash := hk_p19_inspection_claim_reassignment_source_hash(
                    NEW.evidence_version,
                    NEW.property_id::text,
                    NEW.room_inspection_id::text,
                    source_inspection.supervisor_id::text,
                    to_char(source_inspection.claimed_at, 'YYYY-MM-DD"T"HH24:MI:SS.US'),
                    source_inspection.claim_idempotency_key,
                    source_inspection.claim_source_hash,
                    source_inspection.claim_evidence_version,
                    source_task.id::text,
                    source_task.completed_by::text,
                    NEW.original_claimant_id::text,
                    NEW.replacement_claimant_id::text,
                    NEW.intervened_by::text,
                    NEW.original_ineligibility_code,
                    NEW.reason,
                    NEW.idempotency_key
                );
                IF NEW.source_hash IS DISTINCT FROM expected_hash THEN
                    RAISE EXCEPTION 'HK_P19_INSPECTION_CLAIM_RECOVERY_HASH_INVALID';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hk_p19_inspection_claim_reassignment_immutable()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'HK_P19_INSPECTION_CLAIM_RECOVERY_DELETE_PROHIBITED';
                END IF;
                RAISE EXCEPTION 'HK_P19_INSPECTION_CLAIM_RECOVERY_IMMUTABLE';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('CREATE TRIGGER hk_p19_reassignment_insert_guard_trigger BEFORE INSERT ON housekeeping_inspection_claim_reassignments FOR EACH ROW EXECUTE FUNCTION hk_p19_inspection_claim_reassignment_insert_guard()');
        DB::statement('CREATE TRIGGER hk_p19_reassignment_immutable_trigger BEFORE UPDATE OR DELETE ON housekeeping_inspection_claim_reassignments FOR EACH ROW EXECUTE FUNCTION hk_p19_inspection_claim_reassignment_immutable()');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS hk_p19_reassignment_insert_guard_trigger ON housekeeping_inspection_claim_reassignments');
            DB::statement('DROP TRIGGER IF EXISTS hk_p19_reassignment_immutable_trigger ON housekeeping_inspection_claim_reassignments');
            DB::statement('DROP FUNCTION IF EXISTS hk_p19_inspection_claim_reassignment_insert_guard()');
            DB::statement('DROP FUNCTION IF EXISTS hk_p19_inspection_claim_reassignment_immutable()');
            DB::statement('DROP FUNCTION IF EXISTS hk_p19_inspection_claim_reassignment_source_hash(integer, text, text, text, text, text, text, integer, text, text, text, text, text, text, text, text)');
            DB::statement('DROP FUNCTION IF EXISTS hk_p19_user_has_property_permission(text, text, text)');
        }

        Schema::dropIfExists('housekeeping_inspection_claim_reassignments');
    }
};
