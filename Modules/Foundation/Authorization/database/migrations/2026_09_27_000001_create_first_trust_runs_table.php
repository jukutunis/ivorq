<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertPostgreSql();

        if (Schema::hasTable('owner_activations')) {
            DB::statement('LOCK TABLE owner_activations IN SHARE ROW EXCLUSIVE MODE');

            if (DB::table('owner_activations')->exists()) {
                throw new RuntimeException('B5A2A_EXISTING_OWNER_ACTIVATION_RECONCILIATION_REQUIRED');
            }
        }

        Schema::create('first_trust_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('environment', 20);
            $table->string('installation_id', 100);
            $table->string('status', 20);
            $table->string('execution_id', 200);
            $table->string('authorization_id', 200);
            $table->string('authority_reference', 200);
            $table->string('authority_issuer', 300);
            $table->char('request_fingerprint', 64);
            $table->char('canonical_sha', 40);
            $table->string('verifier_key_id', 300);
            $table->char('verifier_bundle_fingerprint', 64);
            $table->string('reservation_reference', 200);
            $table->char('reservation_fingerprint', 64);
            $table->timestamp('reservation_reserved_at');
            $table->timestamp('reservation_commit_deadline');
            $table->timestamp('reservation_recovery_deadline');
            $table->timestamp('started_at');

            $table->foreignUlid('user_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('property_id')->nullable()->constrained('properties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('membership_user_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUlid('membership_property_id')->nullable()->constrained('properties')->restrictOnDelete()->restrictOnUpdate();
            $table->char('membership_fingerprint', 64)->nullable();
            $table->foreignUlid('owner_activation_id')->nullable()->constrained('owner_activations')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('foundation_committed_at')->nullable();

            $table->string('consumption_reference', 200)->nullable();
            $table->char('consumption_fingerprint', 64)->nullable();
            $table->timestamp('external_consumed_at')->nullable();

            $table->char('completion_evidence_fingerprint', 64)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->timestamps();

            $table->unique(['environment', 'installation_id'], 'uq_first_trust_run_installation');
            $table->unique('execution_id', 'uq_first_trust_run_execution');
            $table->unique('authorization_id', 'uq_first_trust_run_authorization');
            $table->unique('owner_activation_id', 'uq_first_trust_run_owner_activation');
        });

        DB::statement("ALTER TABLE first_trust_runs ADD CONSTRAINT chk_first_trust_run_environment CHECK (environment IN ('operational','rehearsal'))");
        DB::statement("ALTER TABLE first_trust_runs ADD CONSTRAINT chk_first_trust_run_status CHECK (status IN ('IN_PROGRESS','COMPLETED','FAILED'))");
        DB::statement(<<<'SQL'
            ALTER TABLE first_trust_runs
            ADD CONSTRAINT chk_first_trust_run_nonblank_identity
            CHECK (
                btrim(installation_id) <> ''
                AND btrim(execution_id) <> ''
                AND btrim(authorization_id) <> ''
                AND btrim(authority_reference) <> ''
                AND btrim(authority_issuer) <> ''
                AND btrim(verifier_key_id) <> ''
                AND btrim(reservation_reference) <> ''
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE first_trust_runs
            ADD CONSTRAINT chk_first_trust_run_hashes
            CHECK (
                request_fingerprint ~ '^[a-f0-9]{64}$'
                AND canonical_sha ~ '^[a-f0-9]{40}$'
                AND verifier_bundle_fingerprint ~ '^[a-f0-9]{64}$'
                AND reservation_fingerprint ~ '^[a-f0-9]{64}$'
                AND (membership_fingerprint IS NULL OR membership_fingerprint ~ '^[a-f0-9]{64}$')
                AND (consumption_fingerprint IS NULL OR consumption_fingerprint ~ '^[a-f0-9]{64}$')
                AND (completion_evidence_fingerprint IS NULL OR completion_evidence_fingerprint ~ '^[a-f0-9]{64}$')
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE first_trust_runs
            ADD CONSTRAINT chk_first_trust_run_reservation_times
            CHECK (
                reservation_reserved_at < reservation_commit_deadline
                AND reservation_commit_deadline <= reservation_recovery_deadline
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE first_trust_runs
            ADD CONSTRAINT chk_first_trust_run_result_set
            CHECK (
                (
                    user_id IS NULL
                    AND company_id IS NULL
                    AND property_id IS NULL
                    AND membership_user_id IS NULL
                    AND membership_property_id IS NULL
                    AND membership_fingerprint IS NULL
                    AND owner_activation_id IS NULL
                    AND foundation_committed_at IS NULL
                )
                OR
                (
                    user_id IS NOT NULL
                    AND company_id IS NOT NULL
                    AND property_id IS NOT NULL
                    AND membership_user_id = user_id
                    AND membership_property_id = property_id
                    AND membership_fingerprint IS NOT NULL
                    AND owner_activation_id IS NOT NULL
                    AND foundation_committed_at IS NOT NULL
                )
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE first_trust_runs
            ADD CONSTRAINT chk_first_trust_run_consumption_set
            CHECK (
                (
                    consumption_reference IS NULL
                    AND consumption_fingerprint IS NULL
                    AND external_consumed_at IS NULL
                )
                OR
                (
                    consumption_reference IS NOT NULL
                    AND btrim(consumption_reference) <> ''
                    AND consumption_fingerprint IS NOT NULL
                    AND external_consumed_at IS NOT NULL
                    AND foundation_committed_at IS NOT NULL
                )
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE first_trust_runs
            ADD CONSTRAINT chk_first_trust_run_lifecycle
            CHECK (
                (
                    status = 'IN_PROGRESS'
                    AND completion_evidence_fingerprint IS NULL
                    AND completed_at IS NULL
                    AND failed_at IS NULL
                    AND failure_code IS NULL
                )
                OR
                (
                    status = 'COMPLETED'
                    AND foundation_committed_at IS NOT NULL
                    AND external_consumed_at IS NOT NULL
                    AND completion_evidence_fingerprint IS NOT NULL
                    AND completed_at IS NOT NULL
                    AND failed_at IS NULL
                    AND failure_code IS NULL
                )
                OR
                (
                    status = 'FAILED'
                    AND foundation_committed_at IS NULL
                    AND consumption_reference IS NULL
                    AND consumption_fingerprint IS NULL
                    AND external_consumed_at IS NULL
                    AND completion_evidence_fingerprint IS NULL
                    AND completed_at IS NULL
                    AND failed_at IS NOT NULL
                    AND failure_code IS NOT NULL
                    AND btrim(failure_code) <> ''
                )
            )
        SQL);

        DB::statement('DROP FUNCTION IF EXISTS first_trust_runs_guard() CASCADE');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION first_trust_runs_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'B5A2A_FIRST_TRUST_RUN_DELETE_REJECTED' USING ERRCODE = 'P0001';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'IN_PROGRESS' THEN
                        RAISE EXCEPTION 'B5A2A_FIRST_TRUST_RUN_INSERT_STATUS_INVALID' USING ERRCODE = 'P0001';
                    END IF;

                    IF NEW.user_id IS NOT NULL
                        OR NEW.company_id IS NOT NULL
                        OR NEW.property_id IS NOT NULL
                        OR NEW.membership_user_id IS NOT NULL
                        OR NEW.membership_property_id IS NOT NULL
                        OR NEW.membership_fingerprint IS NOT NULL
                        OR NEW.owner_activation_id IS NOT NULL
                        OR NEW.foundation_committed_at IS NOT NULL
                        OR NEW.consumption_reference IS NOT NULL
                        OR NEW.consumption_fingerprint IS NOT NULL
                        OR NEW.external_consumed_at IS NOT NULL
                        OR NEW.completion_evidence_fingerprint IS NOT NULL
                        OR NEW.completed_at IS NOT NULL
                        OR NEW.failed_at IS NOT NULL
                        OR NEW.failure_code IS NOT NULL
                    THEN
                        RAISE EXCEPTION 'B5A2A_FIRST_TRUST_RUN_INSERT_STATE_INVALID' USING ERRCODE = 'P0001';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.status IN ('COMPLETED', 'FAILED') THEN
                    RAISE EXCEPTION 'B5A2A_FIRST_TRUST_RUN_TERMINAL_IMMUTABLE' USING ERRCODE = 'P0001';
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.environment IS DISTINCT FROM OLD.environment
                    OR NEW.installation_id IS DISTINCT FROM OLD.installation_id
                    OR NEW.execution_id IS DISTINCT FROM OLD.execution_id
                    OR NEW.authorization_id IS DISTINCT FROM OLD.authorization_id
                    OR NEW.authority_reference IS DISTINCT FROM OLD.authority_reference
                    OR NEW.authority_issuer IS DISTINCT FROM OLD.authority_issuer
                    OR NEW.request_fingerprint IS DISTINCT FROM OLD.request_fingerprint
                    OR NEW.canonical_sha IS DISTINCT FROM OLD.canonical_sha
                    OR NEW.verifier_key_id IS DISTINCT FROM OLD.verifier_key_id
                    OR NEW.verifier_bundle_fingerprint IS DISTINCT FROM OLD.verifier_bundle_fingerprint
                    OR NEW.reservation_reference IS DISTINCT FROM OLD.reservation_reference
                    OR NEW.reservation_fingerprint IS DISTINCT FROM OLD.reservation_fingerprint
                    OR NEW.reservation_reserved_at IS DISTINCT FROM OLD.reservation_reserved_at
                    OR NEW.reservation_commit_deadline IS DISTINCT FROM OLD.reservation_commit_deadline
                    OR NEW.reservation_recovery_deadline IS DISTINCT FROM OLD.reservation_recovery_deadline
                    OR NEW.started_at IS DISTINCT FROM OLD.started_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'B5A2A_FIRST_TRUST_RUN_IDENTITY_IMMUTABLE' USING ERRCODE = 'P0001';
                END IF;

                IF OLD.user_id IS NOT NULL
                    AND (
                        NEW.user_id IS DISTINCT FROM OLD.user_id
                        OR NEW.company_id IS DISTINCT FROM OLD.company_id
                        OR NEW.property_id IS DISTINCT FROM OLD.property_id
                        OR NEW.membership_user_id IS DISTINCT FROM OLD.membership_user_id
                        OR NEW.membership_property_id IS DISTINCT FROM OLD.membership_property_id
                        OR NEW.membership_fingerprint IS DISTINCT FROM OLD.membership_fingerprint
                        OR NEW.owner_activation_id IS DISTINCT FROM OLD.owner_activation_id
                        OR NEW.foundation_committed_at IS DISTINCT FROM OLD.foundation_committed_at
                    )
                THEN
                    RAISE EXCEPTION 'B5A2A_FIRST_TRUST_RUN_RESULT_IMMUTABLE' USING ERRCODE = 'P0001';
                END IF;

                IF OLD.consumption_reference IS NOT NULL
                    AND (
                        NEW.consumption_reference IS DISTINCT FROM OLD.consumption_reference
                        OR NEW.consumption_fingerprint IS DISTINCT FROM OLD.consumption_fingerprint
                        OR NEW.external_consumed_at IS DISTINCT FROM OLD.external_consumed_at
                    )
                THEN
                    RAISE EXCEPTION 'B5A2A_FIRST_TRUST_RUN_CONSUMPTION_IMMUTABLE' USING ERRCODE = 'P0001';
                END IF;

                IF OLD.consumption_reference IS NULL
                    AND NEW.consumption_reference IS NOT NULL
                    AND NEW.foundation_committed_at IS NULL
                THEN
                    RAISE EXCEPTION 'B5A2A_FIRST_TRUST_RUN_CONSUMPTION_BEFORE_FOUNDATION' USING ERRCODE = 'P0001';
                END IF;

                IF NEW.status NOT IN ('IN_PROGRESS', 'COMPLETED', 'FAILED') THEN
                    RAISE EXCEPTION 'B5A2A_FIRST_TRUST_RUN_TRANSITION_INVALID' USING ERRCODE = 'P0001';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_first_trust_runs_guard
            BEFORE INSERT OR UPDATE OR DELETE ON first_trust_runs
            FOR EACH ROW EXECUTE FUNCTION first_trust_runs_guard();
        SQL);
    }

    public function down(): void
    {
        $this->assertPostgreSql();

        if (! Schema::hasTable('first_trust_runs')) {
            return;
        }

        DB::statement('LOCK TABLE first_trust_runs IN ACCESS EXCLUSIVE MODE');
        if (DB::table('first_trust_runs')->exists()) {
            throw new RuntimeException('B5A2A_FIRST_TRUST_RUN_ROLLBACK_RECONCILIATION_REQUIRED');
        }

        DB::statement('DROP TRIGGER IF EXISTS trg_first_trust_runs_guard ON first_trust_runs');
        Schema::drop('first_trust_runs');
        DB::statement('DROP FUNCTION IF EXISTS first_trust_runs_guard()');
    }

    private function assertPostgreSql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('B5A2A_POSTGRESQL_REQUIRED');
        }
    }
};
