<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            CREATE OR REPLACE FUNCTION fn_block_property_currency_change()
            RETURNS TRIGGER AS \$\$
            BEGIN
                IF NEW.currency IS DISTINCT FROM OLD.currency THEN
                    RAISE EXCEPTION 'Property base currency is immutable and cannot be changed.'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_block_property_currency_change ON properties;
            CREATE TRIGGER trg_block_property_currency_change
                BEFORE UPDATE ON properties
                FOR EACH ROW
                EXECUTE FUNCTION fn_block_property_currency_change();
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_block_property_currency_change ON properties');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_block_property_currency_change()');
    }
};
