<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_authority_enrollment_groups', function (Blueprint $table) {
            $table->char('id', 26)->primary();

            // Group identity — no cross-module FKs; plain identifiers only
            $table->char('property_id', 26);
            $table->char('item_id', 26);

            // Lifecycle status
            $table->string('status', 20)->default('draft');

            // Approval evidence — required when status transitions to approved
            $table->char('approved_by', 26)->nullable();
            $table->timestampTz('approved_at')->nullable();

            // Rejection evidence — required when status transitions to rejected
            $table->char('rejected_by', 26)->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejected_reason')->nullable();

            // Supersession evidence — required when status transitions to superseded
            $table->char('superseded_by', 26)->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->text('superseded_reason')->nullable();

            // Enrollment evidence — required when status transitions to enrolled
            $table->timestampTz('enrolled_at')->nullable();

            $table->timestamps();
        });

        // Status constraint
        DB::statement(
            "ALTER TABLE cost_authority_enrollment_groups
             ADD CONSTRAINT chk_caeg_status
             CHECK (status IN ('draft','approved','enrolled','rejected','superseded'))"
        );

        // Lifecycle reads: look up by property + item, filtering by status
        DB::statement(
            'CREATE INDEX idx_caeg_property_item_status
             ON cost_authority_enrollment_groups (property_id, item_id, status)'
        );

        if (DB::getDriverName() === 'pgsql') {

            // Partial unique index: only one enrolled group per property + item
            DB::statement(
                "CREATE UNIQUE INDEX uk_caeg_one_enrolled_per_property_item
                 ON cost_authority_enrollment_groups (property_id, item_id)
                 WHERE status = 'enrolled'"
            );

            // ----------------------------------------------------------------
            // Lifecycle trigger: guard status transitions and field immutability
            // ----------------------------------------------------------------
            DB::statement("
                CREATE OR REPLACE FUNCTION guard_caeg_lifecycle()
                RETURNS TRIGGER AS \$\$
                BEGIN
                    -- -------------------------------------------------------
                    -- Identity fields are immutable once written
                    -- -------------------------------------------------------
                    IF NEW.property_id IS DISTINCT FROM OLD.property_id THEN
                        RAISE EXCEPTION 'cost_authority_enrollment_groups: property_id is immutable';
                    END IF;

                    IF NEW.item_id IS DISTINCT FROM OLD.item_id THEN
                        RAISE EXCEPTION 'cost_authority_enrollment_groups: item_id is immutable';
                    END IF;

                    -- -------------------------------------------------------
                    -- Fully terminal states cannot be updated
                    -- -------------------------------------------------------
                    IF OLD.status IN ('enrolled', 'rejected', 'superseded') THEN
                        RAISE EXCEPTION 'cost_authority_enrollment_groups: status=% records are immutable and cannot be modified', OLD.status;
                    END IF;

                    -- -------------------------------------------------------
                    -- Permitted status transitions only
                    -- -------------------------------------------------------
                    IF OLD.status = 'draft' AND NEW.status NOT IN ('draft', 'approved', 'rejected') THEN
                        RAISE EXCEPTION 'cost_authority_enrollment_groups: invalid transition draft->%', NEW.status;
                    END IF;

                    IF OLD.status = 'approved' AND NEW.status NOT IN ('approved', 'enrolled', 'superseded') THEN
                        RAISE EXCEPTION 'cost_authority_enrollment_groups: invalid transition approved->%', NEW.status;
                    END IF;

                    -- -------------------------------------------------------
                    -- Transition evidence requirements
                    -- -------------------------------------------------------
                    IF NEW.status = 'approved' AND OLD.status = 'draft' THEN
                        IF NEW.approved_by IS NULL OR NEW.approved_at IS NULL THEN
                            RAISE EXCEPTION 'cost_authority_enrollment_groups: draft->approved requires approved_by and approved_at';
                        END IF;
                    END IF;

                    IF NEW.status = 'rejected' AND OLD.status = 'draft' THEN
                        IF NEW.rejected_by IS NULL OR NEW.rejected_at IS NULL OR trim(NEW.rejected_reason) = '' THEN
                            RAISE EXCEPTION 'cost_authority_enrollment_groups: draft->rejected requires rejected_by, rejected_at, and non-empty rejected_reason';
                        END IF;
                    END IF;

                    IF NEW.status = 'superseded' AND OLD.status = 'approved' THEN
                        IF NEW.superseded_by IS NULL OR NEW.superseded_at IS NULL OR trim(NEW.superseded_reason) = '' THEN
                            RAISE EXCEPTION 'cost_authority_enrollment_groups: approved->superseded requires superseded_by, superseded_at, and non-empty superseded_reason';
                        END IF;
                    END IF;

                    IF NEW.status = 'enrolled' AND OLD.status = 'approved' THEN
                        IF NEW.enrolled_at IS NULL THEN
                            RAISE EXCEPTION 'cost_authority_enrollment_groups: approved->enrolled requires enrolled_at';
                        END IF;
                    END IF;

                    -- -------------------------------------------------------
                    -- Approved evidence is immutable once set
                    -- -------------------------------------------------------
                    IF OLD.status IN ('approved', 'enrolled') THEN
                        IF NEW.approved_by IS DISTINCT FROM OLD.approved_by THEN
                            RAISE EXCEPTION 'cost_authority_enrollment_groups: approved_by is immutable after approval';
                        END IF;
                        IF NEW.approved_at IS DISTINCT FROM OLD.approved_at THEN
                            RAISE EXCEPTION 'cost_authority_enrollment_groups: approved_at is immutable after approval';
                        END IF;
                    END IF;

                    RETURN NEW;
                END;
                \$\$ LANGUAGE plpgsql;
            ");

            DB::statement("
                CREATE TRIGGER trg_caeg_guard_lifecycle
                BEFORE UPDATE ON cost_authority_enrollment_groups
                FOR EACH ROW EXECUTE FUNCTION guard_caeg_lifecycle();
            ");

            // ----------------------------------------------------------------
            // Delete guard: approved, enrolled, rejected, superseded rows
            // ----------------------------------------------------------------
            DB::statement("
                CREATE OR REPLACE FUNCTION guard_caeg_no_delete()
                RETURNS TRIGGER AS \$\$
                BEGIN
                    IF OLD.status IN ('approved', 'enrolled', 'rejected', 'superseded') THEN
                        RAISE EXCEPTION 'cost_authority_enrollment_groups: status=% records cannot be deleted', OLD.status;
                    END IF;
                    RETURN OLD;
                END;
                \$\$ LANGUAGE plpgsql;
            ");

            DB::statement("
                CREATE TRIGGER trg_caeg_no_delete
                BEFORE DELETE ON cost_authority_enrollment_groups
                FOR EACH ROW EXECUTE FUNCTION guard_caeg_no_delete();
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_caeg_guard_lifecycle ON cost_authority_enrollment_groups');
            DB::statement('DROP TRIGGER IF EXISTS trg_caeg_no_delete ON cost_authority_enrollment_groups');
            DB::statement('DROP FUNCTION IF EXISTS guard_caeg_lifecycle()');
            DB::statement('DROP FUNCTION IF EXISTS guard_caeg_no_delete()');
        }

        Schema::dropIfExists('cost_authority_enrollment_groups');
    }
};
