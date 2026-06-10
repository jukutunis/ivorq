<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('purchase_order_id', 26);
            $table->char('purchase_request_line_id', 26)->nullable();
            $table->char('inventory_item_id', 26)->nullable();
            $table->string('description', 255);
            $table->decimal('quantity_ordered', 14, 3);
            $table->decimal('quantity_received', 14, 3)->default(0);
            $table->char('unit_id', 26);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
            $table->foreign('purchase_request_line_id')->references('id')->on('purchase_request_lines')->nullOnDelete();
            $table->foreign('inventory_item_id')->references('id')->on('inventory_items')->nullOnDelete();
            $table->foreign('unit_id')->references('id')->on('inventory_units')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
