<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id');

            $table->ulid('inventory_item_id');
            $table->ulid('inventory_location_id');
            $table->ulid('inventory_unit_id');

            $table->string('movement_type');
            $table->string('direction');

            $table->decimal('quantity', 12, 3);

            $table->string('source_domain');
            $table->string('source_type');
            $table->ulid('source_id');

            $table->string('correlation_id');
            $table->string('idempotency_key');

            $table->timestamp('occurred_at');
            $table->ulid('created_by');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['property_id', 'source_type', 'source_id'],
                'uq_inventory_stock_movements_property_source');
            $table->unique(['property_id', 'idempotency_key'],
                'uq_inventory_stock_movements_property_idempotency');

            $table->index(['property_id', 'inventory_item_id', 'inventory_location_id'],
                'idx_inventory_stock_movements_projection');
            $table->index(['property_id', 'movement_type'],
                'idx_inventory_stock_movements_movement_type');
            $table->index(['source_type', 'source_id'],
                'idx_inventory_stock_movements_source');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_movements');
    }
};
