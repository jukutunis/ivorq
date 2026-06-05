<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable append-only ledger: no updated_at, no deleted_at, no updated_by
        Schema::create('inventory_stock_cards', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('item_id', 26);
            $table->char('location_id', 26);
            $table->string('movement_type', 30);
            $table->decimal('quantity_before', 10, 3);
            $table->decimal('quantity_change', 10, 3);
            $table->decimal('quantity_after', 10, 3);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('total_value', 14, 4)->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->char('reference_id', 26)->nullable();
            $table->text('remarks')->nullable();
            $table->char('posted_by', 26);
            $table->dateTime('posted_at');

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('inventory_items')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $table->foreign('posted_by')->references('id')->on('users')->restrictOnDelete();

            $table->index(['property_id', 'item_id', 'posted_at']);
            $table->index(['property_id', 'location_id', 'posted_at']);
            $table->index(['property_id', 'item_id', 'location_id', 'posted_at']);
            $table->index(['property_id', 'movement_type', 'posted_at']);
            $table->index(['property_id', 'reference_type', 'reference_id']);
            $table->index(['property_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_cards');
    }
};
