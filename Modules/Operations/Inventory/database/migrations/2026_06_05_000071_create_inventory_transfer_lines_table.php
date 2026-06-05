<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No deleted_at, no created_by, no updated_by — cascade-deletes with header
        Schema::create('inventory_transfer_lines', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('transfer_id', 26);
            $table->char('item_id', 26);
            $table->decimal('quantity_requested', 10, 3);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('transfer_id')->references('id')->on('inventory_transfers')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('inventory_items')->restrictOnDelete();

            $table->index(['transfer_id']);
            $table->index(['property_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_lines');
    }
};
