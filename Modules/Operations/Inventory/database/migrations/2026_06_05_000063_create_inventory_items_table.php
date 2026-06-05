<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('item_code', 20);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->char('category_id', 26);
            $table->char('unit_id', 26);
            $table->string('sku', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->decimal('min_stock', 10, 3)->default(0);
            $table->decimal('max_stock', 10, 3)->nullable();
            $table->decimal('reorder_point', 10, 3)->default(0);
            $table->decimal('reorder_quantity', 10, 3)->default(0);
            $table->decimal('average_cost', 14, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('category_id')->references('id')->on('inventory_categories')->restrictOnDelete();
            $table->foreign('unit_id')->references('id')->on('inventory_units')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'item_code']);
            $table->unique(['property_id', 'sku']); // NULLs naturally excluded in PostgreSQL
            $table->index(['property_id', 'category_id']);
            $table->index(['property_id', 'unit_id']);
            $table->index(['property_id', 'is_active']);
            $table->index(['property_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
