<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_business_dates', function (Blueprint $table) {
            $table->string('timezone_snapshot')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            ALTER TABLE property_business_dates
            ADD CONSTRAINT chk_property_business_dates_timezone_snapshot_nonblank
            CHECK (timezone_snapshot IS NULL OR btrim(timezone_snapshot) <> '')
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION property_business_dates_bd_a1_foundation_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'BD_A1_PROPERTY_BUSINESS_DATE_DELETE_REJECTED' USING ERRCODE = 'P0001';
                END IF;

                IF NEW.property_id IS DISTINCT FROM OLD.property_id
                    OR NEW.business_date IS DISTINCT FROM OLD.business_date
                    OR NEW.timezone_snapshot IS DISTINCT FROM OLD.timezone_snapshot
                    OR NEW.opened_by IS DISTINCT FROM OLD.opened_by
                    OR NEW.opened_at IS DISTINCT FROM OLD.opened_at
                THEN
                    RAISE EXCEPTION 'BD_A1_PROPERTY_BUSINESS_DATE_FOUNDATION_IMMUTABLE' USING ERRCODE = 'P0001';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        ");

        DB::statement("
            CREATE TRIGGER trg_property_business_dates_bd_a1_foundation_guard
            BEFORE UPDATE OR DELETE ON property_business_dates
            FOR EACH ROW
            EXECUTE FUNCTION property_business_dates_bd_a1_foundation_guard()
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_property_business_dates_bd_a1_foundation_guard ON property_business_dates');
            DB::statement('DROP FUNCTION IF EXISTS property_business_dates_bd_a1_foundation_guard()');
            DB::statement('ALTER TABLE property_business_dates DROP CONSTRAINT IF EXISTS chk_property_business_dates_timezone_snapshot_nonblank');
        }

        Schema::table('property_business_dates', function (Blueprint $table) {
            $table->dropColumn('timezone_snapshot');
        });
    }
};
