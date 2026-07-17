<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('night_audit_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('property_business_date_id')->constrained('property_business_dates')->restrictOnDelete();
            $table->date('business_date_snapshot');
            $table->string('property_timezone_snapshot');
            $table->unsignedInteger('attempt_number');
            $table->string('status');
            $table->foreignUlid('started_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->foreignUlid('aborted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('aborted_at')->nullable();
            $table->string('abort_reason', 500)->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['property_business_date_id', 'attempt_number'], 'uq_night_audit_run_attempt');
            $table->index(['property_id', 'property_business_date_id'], 'idx_night_audit_runs_property_date');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            ALTER TABLE night_audit_runs
            ADD CONSTRAINT chk_night_audit_runs_attempt_positive
            CHECK (attempt_number > 0)
        ");

        DB::statement("
            ALTER TABLE night_audit_runs
            ADD CONSTRAINT chk_night_audit_runs_timezone_nonblank
            CHECK (btrim(property_timezone_snapshot) <> '')
        ");

        DB::statement("
            ALTER TABLE night_audit_runs
            ADD CONSTRAINT chk_night_audit_runs_status_valid
            CHECK (status IN ('IN_PROGRESS', 'ABORTED'))
        ");

        DB::statement("
            ALTER TABLE night_audit_runs
            ADD CONSTRAINT chk_night_audit_runs_abort_fields
            CHECK (
                (status = 'IN_PROGRESS' AND aborted_by IS NULL AND aborted_at IS NULL AND abort_reason IS NULL)
                OR
                (status = 'ABORTED' AND aborted_by IS NOT NULL AND aborted_at IS NOT NULL AND btrim(abort_reason) <> '')
            )
        ");

        DB::statement("
            CREATE UNIQUE INDEX uq_night_audit_runs_one_active_per_property
            ON night_audit_runs (property_id)
            WHERE status = 'IN_PROGRESS'
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION night_audit_runs_na_a1_evidence_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'NA_A1_NIGHT_AUDIT_RUN_DELETE_REJECTED' USING ERRCODE = 'P0001';
                END IF;

                IF NEW.property_id IS DISTINCT FROM OLD.property_id
                    OR NEW.property_business_date_id IS DISTINCT FROM OLD.property_business_date_id
                    OR NEW.business_date_snapshot IS DISTINCT FROM OLD.business_date_snapshot
                    OR NEW.property_timezone_snapshot IS DISTINCT FROM OLD.property_timezone_snapshot
                    OR NEW.attempt_number IS DISTINCT FROM OLD.attempt_number
                    OR NEW.started_by IS DISTINCT FROM OLD.started_by
                    OR NEW.started_at IS DISTINCT FROM OLD.started_at
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'NA_A1_NIGHT_AUDIT_RUN_FOUNDATION_IMMUTABLE' USING ERRCODE = 'P0001';
                END IF;

                IF OLD.status = 'IN_PROGRESS' AND NEW.status = 'ABORTED' THEN
                    IF NEW.aborted_by IS NULL
                        OR NEW.aborted_at IS NULL
                        OR NEW.abort_reason IS NULL
                        OR btrim(NEW.abort_reason) = ''
                        OR NEW.updated_by IS NULL
                        OR NEW.updated_at IS NULL
                    THEN
                        RAISE EXCEPTION 'NA_A1_NIGHT_AUDIT_ABORT_EVIDENCE_INCOMPLETE' USING ERRCODE = 'P0001';
                    END IF;

                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'NA_A1_NIGHT_AUDIT_RUN_UPDATE_REJECTED' USING ERRCODE = 'P0001';
            END;
            $$ LANGUAGE plpgsql
        ");

        DB::statement("
            CREATE TRIGGER trg_night_audit_runs_na_a1_evidence_guard
            BEFORE UPDATE OR DELETE ON night_audit_runs
            FOR EACH ROW
            EXECUTE FUNCTION night_audit_runs_na_a1_evidence_guard()
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_night_audit_runs_na_a1_evidence_guard ON night_audit_runs');
            DB::statement('DROP FUNCTION IF EXISTS night_audit_runs_na_a1_evidence_guard()');
            DB::statement('DROP INDEX IF EXISTS uq_night_audit_runs_one_active_per_property');
        }

        Schema::dropIfExists('night_audit_runs');
    }
};
