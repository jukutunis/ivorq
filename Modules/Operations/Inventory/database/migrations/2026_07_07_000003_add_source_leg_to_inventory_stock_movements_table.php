<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table) {
            $table->dropUnique('uq_inventory_stock_movements_property_source');
            $table->string('source_leg')->default('PRIMARY');
            $table->unique(['property_id', 'source_type', 'source_id', 'source_leg'],
                'uq_inventory_stock_movements_property_source_leg');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table) {
            $table->dropUnique('uq_inventory_stock_movements_property_source_leg');
            $table->dropColumn('source_leg');
            $table->unique(['property_id', 'source_type', 'source_id'],
                'uq_inventory_stock_movements_property_source');
        });
    }
};
