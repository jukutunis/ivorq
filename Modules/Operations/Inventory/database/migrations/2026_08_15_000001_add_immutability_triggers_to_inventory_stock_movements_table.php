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
            CREATE OR REPLACE FUNCTION fn_block_inventory_stock_movement_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Inventory stock movements are immutable and cannot be updated or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_block_inventory_stock_movement_update
            BEFORE UPDATE ON inventory_stock_movements
            FOR EACH ROW EXECUTE FUNCTION fn_block_inventory_stock_movement_mutation();

            CREATE TRIGGER trg_block_inventory_stock_movement_delete
            BEFORE DELETE ON inventory_stock_movements
            FOR EACH ROW EXECUTE FUNCTION fn_block_inventory_stock_movement_mutation();
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            DROP TRIGGER IF EXISTS trg_block_inventory_stock_movement_delete ON inventory_stock_movements;
            DROP TRIGGER IF EXISTS trg_block_inventory_stock_movement_update ON inventory_stock_movements;
            DROP FUNCTION IF EXISTS fn_block_inventory_stock_movement_mutation();
        ");
    }
};
