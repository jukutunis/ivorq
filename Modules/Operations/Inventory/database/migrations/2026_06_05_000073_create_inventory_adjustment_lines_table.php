<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No deleted_at, no created_by, no updated_by — cascade-deletes with header
        Schema::create('inventory_adjustment_lines', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('adjustment_id', 26);
            $table->char('item_id', 26);
            $table->decimal('quantity_system', 10, 3);
            $table->decimal('quantity_actual', 10, 3);
            $table->decimal('quantity_variance', 10, 3);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('adjustment_id')->references('id')->on('inventory_adjustments')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('inventory_items')->restrictOnDelete();

            $table->unique(['adjustment_id', 'item_id']);
            $table->index(['adjustment_id']);
            $table->index(['property_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustment_lines');
    }
};
