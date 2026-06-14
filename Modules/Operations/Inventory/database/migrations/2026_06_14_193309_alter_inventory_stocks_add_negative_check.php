<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Avoid SQLite test failures since it does not support ADD CONSTRAINT for CHECK easily
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE inventory_stocks ADD CONSTRAINT chk_inventory_stocks_physical_qty_positive CHECK (physical_quantity >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE inventory_stocks DROP CONSTRAINT chk_inventory_stocks_physical_qty_positive');
        }
    }
};
