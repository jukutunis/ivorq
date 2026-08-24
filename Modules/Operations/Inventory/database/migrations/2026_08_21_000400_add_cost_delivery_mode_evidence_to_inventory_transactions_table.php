<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->string('cost_delivery_mode', 20)->nullable();
            $table->char('cost_delivery_ownership_id', 26)->nullable();
            $table->unsignedBigInteger('cost_delivery_ownership_version')->nullable();
            $table->char('cost_delivery_cutover_id', 26)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE inventory_transactions ADD CONSTRAINT chk_inv_tx_cost_delivery_stamp CHECK ((
                (cost_delivery_mode IS NULL
                    AND cost_delivery_ownership_id IS NULL
                    AND cost_delivery_ownership_version IS NULL
                    AND cost_delivery_cutover_id IS NULL)
                OR
                (cost_delivery_mode = 'SYNCHRONOUS'
                    AND cost_delivery_ownership_id IS NOT NULL
                    AND cost_delivery_ownership_version >= 1
                    AND cost_delivery_cutover_id IS NULL)
                OR
                (cost_delivery_mode = 'DEFERRED'
                    AND cost_delivery_ownership_id IS NOT NULL
                    AND cost_delivery_ownership_version >= 1
                    AND cost_delivery_cutover_id IS NOT NULL)
            ) IS TRUE)");
        }

        DB::statement('CREATE INDEX idx_inv_tx_cost_delivery_mode
            ON inventory_transactions (property_id, item_id, cost_delivery_mode, valuation_sequence)');
        DB::statement('CREATE INDEX idx_inv_tx_cost_delivery_cutover
            ON inventory_transactions (cost_delivery_cutover_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_inv_tx_cost_delivery_cutover');
        DB::statement('DROP INDEX IF EXISTS idx_inv_tx_cost_delivery_mode');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_transactions DROP CONSTRAINT IF EXISTS chk_inv_tx_cost_delivery_stamp');
        }

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'cost_delivery_mode',
                'cost_delivery_ownership_id',
                'cost_delivery_ownership_version',
                'cost_delivery_cutover_id',
            ]);
        });
    }
};
