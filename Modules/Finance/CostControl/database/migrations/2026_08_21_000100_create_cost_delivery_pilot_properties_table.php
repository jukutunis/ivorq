<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_delivery_pilot_properties', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->unsignedSmallInteger('pilot_slot');
            $table->char('property_id', 26);
            $table->string('owner_approval_reference');
            $table->char('authorized_by', 26);
            $table->timestampTz('authorized_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('pilot_slot', 'uk_cdpp_singleton_slot');
            $table->unique('property_id', 'uk_cdpp_property');
            $table->foreign('property_id', 'fk_cdpp_property')
                ->references('id')->on('properties')->restrictOnDelete()->restrictOnUpdate();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE cost_delivery_pilot_properties
            ADD CONSTRAINT chk_cdpp_slot CHECK (pilot_slot = 1)');
        DB::statement("ALTER TABLE cost_delivery_pilot_properties
            ADD CONSTRAINT chk_cdpp_provenance CHECK (
                btrim(owner_approval_reference) <> '' AND btrim(authorized_by) <> ''
            )");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_cdpp_immutable()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'cost_delivery_pilot_properties: immutable append-only evidence';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_cdpp_no_update
            BEFORE UPDATE ON cost_delivery_pilot_properties
            FOR EACH ROW EXECUTE FUNCTION guard_cdpp_immutable();

            CREATE TRIGGER trg_cdpp_no_delete
            BEFORE DELETE ON cost_delivery_pilot_properties
            FOR EACH ROW EXECUTE FUNCTION guard_cdpp_immutable();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_cdpp_no_update ON cost_delivery_pilot_properties');
            DB::statement('DROP TRIGGER IF EXISTS trg_cdpp_no_delete ON cost_delivery_pilot_properties');
            DB::statement('DROP FUNCTION IF EXISTS guard_cdpp_immutable()');
        }

        Schema::dropIfExists('cost_delivery_pilot_properties');
    }
};
