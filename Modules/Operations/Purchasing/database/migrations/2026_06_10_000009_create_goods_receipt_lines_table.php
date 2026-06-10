<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('goods_receipt_id', 26);
            $table->char('purchase_order_line_id', 26);
            $table->char('inventory_item_id', 26);
            $table->char('location_id', 26);
            $table->decimal('quantity_received', 14, 3);
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('line_total', 14, 2);
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('goods_receipt_id')->references('id')->on('goods_receipts')->cascadeOnDelete();
            $table->foreign('purchase_order_line_id')->references('id')->on('purchase_order_lines')->restrictOnDelete();
            $table->foreign('inventory_item_id')->references('id')->on('inventory_items')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('goods_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
    }
};
