<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_delivery_mode_ownerships', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('item_id', 26);
            $table->char('enrollment_group_id', 26);
            $table->string('delivery_mode', 20);
            $table->unsignedBigInteger('ownership_version');
            $table->char('activated_cutover_id', 26)->nullable();
            $table->char('established_by', 26);
            $table->timestampTz('established_at');
            $table->char('changed_by', 26)->nullable();
            $table->timestampTz('changed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['property_id', 'item_id'], 'uk_cdmo_property_item');
            $table->unique('enrollment_group_id', 'uk_cdmo_enrollment_group');
            $table->foreign('property_id', 'fk_cdmo_property')
                ->references('id')->on('properties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('item_id', 'fk_cdmo_item')
                ->references('id')->on('inventory_items')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('enrollment_group_id', 'fk_cdmo_enrollment')
                ->references('id')->on('cost_authority_enrollment_groups')->restrictOnDelete()->restrictOnUpdate();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE cost_delivery_mode_ownerships
            ADD CONSTRAINT chk_cdmo_mode CHECK (delivery_mode IN ('SYNCHRONOUS', 'DEFERRED'))");
        DB::statement('ALTER TABLE cost_delivery_mode_ownerships
            ADD CONSTRAINT chk_cdmo_version CHECK (ownership_version >= 1)');
        DB::statement("ALTER TABLE cost_delivery_mode_ownerships
            ADD CONSTRAINT chk_cdmo_mode_provenance CHECK (
                (delivery_mode = 'SYNCHRONOUS' AND activated_cutover_id IS NULL)
                OR (delivery_mode = 'DEFERRED' AND activated_cutover_id IS NOT NULL)
            )");
        DB::statement("ALTER TABLE cost_delivery_mode_ownerships
            ADD CONSTRAINT chk_cdmo_audit CHECK (
                btrim(established_by) <> ''
                AND ((changed_by IS NULL AND changed_at IS NULL)
                    OR (changed_by IS NOT NULL AND btrim(changed_by) <> '' AND changed_at IS NOT NULL))
            )");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_cdmo_insert()
            RETURNS trigger AS $$
            DECLARE
                group_property_id text;
                group_item_id text;
                group_status text;
                item_property_id text;
            BEGIN
                SELECT property_id, item_id, status
                  INTO group_property_id, group_item_id, group_status
                  FROM cost_authority_enrollment_groups
                 WHERE id = NEW.enrollment_group_id;

                SELECT property_id INTO item_property_id
                  FROM inventory_items
                 WHERE id = NEW.item_id;

                IF group_status IS DISTINCT FROM 'enrolled'
                   OR group_property_id IS DISTINCT FROM NEW.property_id
                   OR group_item_id IS DISTINCT FROM NEW.item_id
                   OR item_property_id IS DISTINCT FROM NEW.property_id THEN
                    RAISE EXCEPTION 'cost_delivery_mode_ownerships: enrollment identity/status mismatch'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.delivery_mode <> 'SYNCHRONOUS'
                   OR NEW.ownership_version <> 1
                   OR NEW.activated_cutover_id IS NOT NULL
                   OR NEW.changed_by IS NOT NULL
                   OR NEW.changed_at IS NOT NULL THEN
                    RAISE EXCEPTION 'cost_delivery_mode_ownerships: initial ownership must be SYNCHRONOUS version 1'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION guard_cdmo_update()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.delivery_mode = 'DEFERRED' THEN
                    RAISE EXCEPTION 'cost_delivery_mode_ownerships: DEFERRED ownership is terminal';
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                   OR NEW.property_id IS DISTINCT FROM OLD.property_id
                   OR NEW.item_id IS DISTINCT FROM OLD.item_id
                   OR NEW.enrollment_group_id IS DISTINCT FROM OLD.enrollment_group_id
                   OR NEW.established_by IS DISTINCT FROM OLD.established_by
                   OR NEW.established_at IS DISTINCT FROM OLD.established_at
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'cost_delivery_mode_ownerships: identity and establishment provenance are immutable';
                END IF;

                IF NEW.delivery_mode <> 'DEFERRED'
                   OR NEW.ownership_version <> OLD.ownership_version + 1
                   OR NEW.activated_cutover_id IS NULL
                   OR NEW.changed_by IS NULL
                   OR btrim(NEW.changed_by) = ''
                   OR NEW.changed_at IS NULL THEN
                    RAISE EXCEPTION 'cost_delivery_mode_ownerships: only SYNCHRONOUS to DEFERRED with exact provenance is allowed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION guard_cdmo_no_delete()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'cost_delivery_mode_ownerships: deletion is prohibited';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_cdmo_insert
            BEFORE INSERT ON cost_delivery_mode_ownerships
            FOR EACH ROW EXECUTE FUNCTION guard_cdmo_insert();

            CREATE TRIGGER trg_cdmo_update
            BEFORE UPDATE ON cost_delivery_mode_ownerships
            FOR EACH ROW EXECUTE FUNCTION guard_cdmo_update();

            CREATE TRIGGER trg_cdmo_no_delete
            BEFORE DELETE ON cost_delivery_mode_ownerships
            FOR EACH ROW EXECUTE FUNCTION guard_cdmo_no_delete();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_cdmo_insert ON cost_delivery_mode_ownerships');
            DB::statement('DROP TRIGGER IF EXISTS trg_cdmo_update ON cost_delivery_mode_ownerships');
            DB::statement('DROP TRIGGER IF EXISTS trg_cdmo_no_delete ON cost_delivery_mode_ownerships');
            DB::statement('DROP FUNCTION IF EXISTS guard_cdmo_insert()');
            DB::statement('DROP FUNCTION IF EXISTS guard_cdmo_update()');
            DB::statement('DROP FUNCTION IF EXISTS guard_cdmo_no_delete()');
        }

        Schema::dropIfExists('cost_delivery_mode_ownerships');
    }
};
