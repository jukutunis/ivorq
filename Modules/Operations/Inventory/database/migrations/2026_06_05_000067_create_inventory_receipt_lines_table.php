<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No deleted_at, no created_by, no updated_by — cascade-deletes with header
        Schema::create('inventory_receipt_lines', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('receipt_id', 26);
            $table->char('item_id', 26);
            $table->char('location_id', 26);
            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('total_value', 14, 4);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('receipt_id')->references('id')->on('inventory_receipts')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('inventory_items')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('inventory_locations')->restrictOnDelete();

            $table->index(['receipt_id']);
            $table->index(['property_id', 'item_id']);
            $table->index(['property_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_receipt_lines');
    }
};
