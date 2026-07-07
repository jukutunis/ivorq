<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id');
            $table->ulid('purchase_order_id');
            $table->string('receipt_number');
            $table->string('status');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->ulid('received_by');
            $table->ulid('created_by');
            $table->timestamp('created_at')->useCurrent();
            $table->index('property_id');
            $table->index('purchase_order_id');
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('goods_receipt_id');
            $table->ulid('property_id');
            $table->ulid('purchase_order_line_id');
            $table->ulid('inventory_item_id');
            $table->ulid('inventory_location_id');
            $table->ulid('inventory_unit_id');
            $table->decimal('received_quantity', 12, 3);
            $table->string('idempotency_key');
            $table->ulid('stock_movement_id')->nullable();
            $table->ulid('created_by');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['property_id', 'idempotency_key'],
                'uq_goods_receipt_lines_idempotency');
            $table->unique('stock_movement_id');

            $table->index('goods_receipt_id');
            $table->index('purchase_order_line_id');
            $table->foreign('goods_receipt_id')
                ->references('id')->on('goods_receipts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
