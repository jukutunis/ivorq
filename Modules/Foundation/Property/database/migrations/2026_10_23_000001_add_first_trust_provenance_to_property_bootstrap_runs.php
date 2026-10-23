<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLLBACK_RECONCILIATION_REQUIRED = 'B5A3_FIRST_TRUST_PROVENANCE_ROLLBACK_RECONCILIATION_REQUIRED';

    public function up(): void
    {
        $this->assertPostgreSql();

        if (! Schema::hasTable('property_bootstrap_provisioning_runs') || ! Schema::hasTable('first_trust_runs')) {
            throw new RuntimeException('B5A3_FIRST_TRUST_PROVENANCE_SCHEMA_MISMATCH');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE property_bootstrap_provisioning_runs, first_trust_runs IN ACCESS EXCLUSIVE MODE');
            DB::statement('ALTER TABLE property_bootstrap_provisioning_runs ADD COLUMN first_trust_run_id CHAR(26) NULL');
            DB::statement(<<<'SQL'
                ALTER TABLE property_bootstrap_provisioning_runs
                ADD CONSTRAINT fk_pbpr_first_trust_run
                FOREIGN KEY (first_trust_run_id) REFERENCES first_trust_runs(id)
                ON UPDATE RESTRICT ON DELETE RESTRICT
            SQL);
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX uq_pbpr_first_trust_run
                ON property_bootstrap_provisioning_runs (first_trust_run_id)
                WHERE first_trust_run_id IS NOT NULL
            SQL);

            $this->installLinkedGuard();
        });
    }

    public function down(): void
    {
        $this->assertPostgreSql();

        if (! Schema::hasColumn('property_bootstrap_provisioning_runs', 'first_trust_run_id')) {
            return;
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE property_bootstrap_provisioning_runs IN ACCESS EXCLUSIVE MODE');

            if (DB::table('property_bootstrap_provisioning_runs')->whereNotNull('first_trust_run_id')->exists()) {
                throw new RuntimeException(self::ROLLBACK_RECONCILIATION_REQUIRED);
            }

            $this->installLegacyGuard();
            DB::statement('DROP INDEX IF EXISTS uq_pbpr_first_trust_run');
            DB::statement('ALTER TABLE property_bootstrap_provisioning_runs DROP CONSTRAINT IF EXISTS fk_pbpr_first_trust_run');
            DB::statement('ALTER TABLE property_bootstrap_provisioning_runs DROP COLUMN first_trust_run_id');
        });
    }

    private function installLinkedGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION property_bootstrap_provisioning_runs_guard()
            RETURNS trigger AS $$
            DECLARE
                linked_first_trust first_trust_runs%ROWTYPE;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'in_progress' THEN
                        RAISE EXCEPTION 'B4C_BOOTSTRAP_PROVISIONING_RUN_INITIAL_STATUS_INVALID' USING ERRCODE = 'P0001';
                    END IF;
                ELSIF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'B4C_BOOTSTRAP_PROVISIONING_RUN_DELETE_REJECTED' USING ERRCODE = 'P0001';
                ELSE
                    IF OLD.status IN ('completed', 'failed') THEN
                        RAISE EXCEPTION 'B4C_BOOTSTRAP_PROVISIONING_RUN_TERMINAL_IMMUTABLE' USING ERRCODE = 'P0001';
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.environment IS DISTINCT FROM OLD.environment
                        OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                        OR NEW.request_fingerprint IS DISTINCT FROM OLD.request_fingerprint
                        OR NEW.canonical_sha IS DISTINCT FROM OLD.canonical_sha
                        OR NEW.source IS DISTINCT FROM OLD.source
                        OR NEW.initiated_by IS DISTINCT FROM OLD.initiated_by
                        OR NEW.started_at IS DISTINCT FROM OLD.started_at
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    THEN
                        RAISE EXCEPTION 'B4C_BOOTSTRAP_PROVISIONING_RUN_IDENTITY_IMMUTABLE' USING ERRCODE = 'P0001';
                    END IF;

                    IF NEW.first_trust_run_id IS DISTINCT FROM OLD.first_trust_run_id THEN
                        RAISE EXCEPTION 'B5A3_FIRST_TRUST_PROVENANCE_IMMUTABLE' USING ERRCODE = 'P0001';
                    END IF;

                    IF OLD.company_id IS NOT NULL
                        AND NEW.company_id IS DISTINCT FROM OLD.company_id
                    THEN
                        RAISE EXCEPTION 'B4C_BOOTSTRAP_PROVISIONING_RUN_COMPANY_IMMUTABLE' USING ERRCODE = 'P0001';
                    END IF;

                    IF OLD.property_id IS NOT NULL
                        AND NEW.property_id IS DISTINCT FROM OLD.property_id
                    THEN
                        RAISE EXCEPTION 'B4C_BOOTSTRAP_PROVISIONING_RUN_PROPERTY_IMMUTABLE' USING ERRCODE = 'P0001';
                    END IF;
                END IF;

                IF NEW.first_trust_run_id IS NOT NULL THEN
                    SELECT * INTO linked_first_trust
                    FROM first_trust_runs
                    WHERE id = NEW.first_trust_run_id;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'B5A3_FIRST_TRUST_PROVENANCE_NOT_FOUND' USING ERRCODE = 'P0001';
                    END IF;

                    IF linked_first_trust.status <> 'COMPLETED' THEN
                        RAISE EXCEPTION 'B5A3_FIRST_TRUST_PROVENANCE_NOT_COMPLETED' USING ERRCODE = 'P0001';
                    END IF;

                    IF linked_first_trust.user_id IS NULL
                        OR linked_first_trust.company_id IS NULL
                        OR linked_first_trust.property_id IS NULL
                        OR linked_first_trust.membership_user_id IS NULL
                        OR linked_first_trust.membership_property_id IS NULL
                        OR linked_first_trust.membership_fingerprint IS NULL
                        OR linked_first_trust.owner_activation_id IS NULL
                        OR linked_first_trust.foundation_committed_at IS NULL
                    THEN
                        RAISE EXCEPTION 'B5A3_FIRST_TRUST_PROVENANCE_RESULT_INCOMPLETE' USING ERRCODE = 'P0001';
                    END IF;

                    IF NEW.environment IS DISTINCT FROM linked_first_trust.environment THEN
                        RAISE EXCEPTION 'B5A3_FIRST_TRUST_PROVENANCE_ENVIRONMENT_MISMATCH' USING ERRCODE = 'P0001';
                    END IF;

                    IF NEW.initiated_by IS DISTINCT FROM linked_first_trust.user_id THEN
                        RAISE EXCEPTION 'B5A3_FIRST_TRUST_PROVENANCE_ACTOR_MISMATCH' USING ERRCODE = 'P0001';
                    END IF;

                    IF NEW.company_id IS NOT NULL
                        AND NEW.company_id IS DISTINCT FROM linked_first_trust.company_id
                    THEN
                        RAISE EXCEPTION 'B5A3_FIRST_TRUST_PROVENANCE_COMPANY_MISMATCH' USING ERRCODE = 'P0001';
                    END IF;

                    IF NEW.property_id IS NOT NULL
                        AND NEW.property_id IS DISTINCT FROM linked_first_trust.property_id
                    THEN
                        RAISE EXCEPTION 'B5A3_FIRST_TRUST_PROVENANCE_PROPERTY_MISMATCH' USING ERRCODE = 'P0001';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    private function installLegacyGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION property_bootstrap_provisioning_runs_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'in_progress' THEN
                        RAISE EXCEPTION 'B4C_BOOTSTRAP_PROVISIONING_RUN_INITIAL_STATUS_INVALID' USING ERRCODE = 'P0001';
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'B4C_BOOTSTRAP_PROVISIONING_RUN_DELETE_REJECTED' USING ERRCODE = 'P0001';
                END IF;

                IF OLD.status IN ('completed', 'failed') THEN
                    RAISE EXCEPTION 'B4C_BOOTSTRAP_PROVISIONING_RUN_TERMINAL_IMMUTABLE' USING ERRCODE = 'P0001';
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.environment IS DISTINCT FROM OLD.environment
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                    OR NEW.request_fingerprint IS DISTINCT FROM OLD.request_fingerprint
                    OR NEW.canonical_sha IS DISTINCT FROM OLD.canonical_sha
                    OR NEW.source IS DISTINCT FROM OLD.source
                    OR NEW.initiated_by IS DISTINCT FROM OLD.initiated_by
                    OR NEW.started_at IS DISTINCT FROM OLD.started_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'B4C_BOOTSTRAP_PROVISIONING_RUN_IDENTITY_IMMUTABLE' USING ERRCODE = 'P0001';
                END IF;

                IF OLD.company_id IS NOT NULL
                    AND NEW.company_id IS DISTINCT FROM OLD.company_id
                THEN
                    RAISE EXCEPTION 'B4C_BOOTSTRAP_PROVISIONING_RUN_COMPANY_IMMUTABLE' USING ERRCODE = 'P0001';
                END IF;

                IF OLD.property_id IS NOT NULL
                    AND NEW.property_id IS DISTINCT FROM OLD.property_id
                THEN
                    RAISE EXCEPTION 'B4C_BOOTSTRAP_PROVISIONING_RUN_PROPERTY_IMMUTABLE' USING ERRCODE = 'P0001';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    private function assertPostgreSql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('B5A3_POSTGRESQL_REQUIRED');
        }
    }
};
