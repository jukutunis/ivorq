<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip for SQLite during testing
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("
                CREATE OR REPLACE FUNCTION block_inventory_transaction_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Inventory transactions are immutable and cannot be updated or deleted.';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trg_block_inventory_transaction_update
                BEFORE UPDATE ON inventory_transactions
                FOR EACH ROW EXECUTE FUNCTION block_inventory_transaction_mutation();

                CREATE TRIGGER trg_block_inventory_transaction_delete
                BEFORE DELETE ON inventory_transactions
                FOR EACH ROW EXECUTE FUNCTION block_inventory_transaction_mutation();
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("
                DROP TRIGGER IF EXISTS trg_block_inventory_transaction_delete ON inventory_transactions;
                DROP TRIGGER IF EXISTS trg_block_inventory_transaction_update ON inventory_transactions;
                DROP FUNCTION IF EXISTS block_inventory_transaction_mutation();
            ");
        }
    }
};
