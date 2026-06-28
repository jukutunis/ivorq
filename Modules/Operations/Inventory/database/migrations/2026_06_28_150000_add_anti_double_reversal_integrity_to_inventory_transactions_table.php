<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('
            CREATE UNIQUE INDEX idx_inventory_transactions_reversal_limit
            ON inventory_transactions (reverses_inventory_transaction_id)
            WHERE reverses_inventory_transaction_id IS NOT NULL;
        ');

        DB::statement('
            ALTER TABLE inventory_transactions
            ADD CONSTRAINT chk_inventory_transactions_no_self_reversal
            CHECK (
                reverses_inventory_transaction_id IS NULL
                OR reverses_inventory_transaction_id <> id
            );
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE inventory_transactions DROP CONSTRAINT IF EXISTS chk_inventory_transactions_no_self_reversal;');
        DB::statement('DROP INDEX IF EXISTS idx_inventory_transactions_reversal_limit;');
    }
};
