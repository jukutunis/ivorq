<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_bootstrap_provisioning_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('environment', 20);
            $table->string('idempotency_key');
            $table->char('request_fingerprint', 64);
            $table->string('status', 20);
            $table->char('canonical_sha', 40);
            $table->string('source');
            $table->foreignUlid('initiated_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('property_id')->nullable()->constrained('properties')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->jsonb('evidence');
            $table->char('evidence_fingerprint', 64)->nullable();
            $table->timestamps();

            $table->unique(['environment', 'idempotency_key'], 'uq_pbpr_environment_idempotency');
            $table->index('company_id', 'idx_pbpr_company');
            $table->index('property_id', 'idx_pbpr_property');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE property_bootstrap_provisioning_runs
            ADD CONSTRAINT chk_pbpr_environment
            CHECK (environment IN ('operational', 'rehearsal'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE property_bootstrap_provisioning_runs
            ADD CONSTRAINT chk_pbpr_status
            CHECK (status IN ('in_progress', 'completed', 'failed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE property_bootstrap_provisioning_runs
            ADD CONSTRAINT chk_pbpr_nonblank_identity
            CHECK (btrim(idempotency_key) <> '' AND btrim(source) <> '')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE property_bootstrap_provisioning_runs
            ADD CONSTRAINT chk_pbpr_request_fingerprint
            CHECK (request_fingerprint ~ '^[0-9A-Fa-f]{64}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE property_bootstrap_provisioning_runs
            ADD CONSTRAINT chk_pbpr_canonical_sha
            CHECK (canonical_sha ~ '^[0-9A-Fa-f]{40}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE property_bootstrap_provisioning_runs
            ADD CONSTRAINT chk_pbpr_evidence_fingerprint
            CHECK (
                evidence_fingerprint IS NULL
                OR evidence_fingerprint ~ '^[0-9A-Fa-f]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE property_bootstrap_provisioning_runs
            ADD CONSTRAINT chk_pbpr_status_evidence
            CHECK (
                (
                    status = 'in_progress'
                    AND completed_at IS NULL
                    AND failed_at IS NULL
                    AND failure_code IS NULL
                    AND evidence_fingerprint IS NULL
                )
                OR
                (
                    status = 'completed'
                    AND completed_at IS NOT NULL
                    AND failed_at IS NULL
                    AND failure_code IS NULL
                    AND evidence_fingerprint IS NOT NULL
                )
                OR
                (
                    status = 'failed'
                    AND failed_at IS NOT NULL
                    AND completed_at IS NULL
                    AND failure_code IS NOT NULL
                    AND btrim(failure_code) <> ''
                    AND evidence_fingerprint IS NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uq_pbpr_active_completed_property
            ON property_bootstrap_provisioning_runs (environment, property_id)
            WHERE property_id IS NOT NULL
              AND status IN ('in_progress', 'completed')
        SQL);

        DB::statement(<<<'SQL'
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

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_property_bootstrap_provisioning_runs_guard
            BEFORE INSERT OR UPDATE OR DELETE ON property_bootstrap_provisioning_runs
            FOR EACH ROW
            EXECUTE FUNCTION property_bootstrap_provisioning_runs_guard()
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_property_bootstrap_provisioning_runs_guard ON property_bootstrap_provisioning_runs');
            DB::statement('DROP FUNCTION IF EXISTS property_bootstrap_provisioning_runs_guard()');
            DB::statement('DROP INDEX IF EXISTS uq_pbpr_active_completed_property');
        }

        Schema::dropIfExists('property_bootstrap_provisioning_runs');
    }
};
