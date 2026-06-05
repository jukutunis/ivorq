<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_balances', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('item_id', 26);
            $table->char('location_id', 26);
            $table->decimal('quantity', 10, 3)->default(0);
            $table->string('status', 30)->default('out_of_stock');
            $table->dateTime('last_movement_at')->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('inventory_items')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('inventory_locations')->restrictOnDelete();

            $table->unique(['property_id', 'item_id', 'location_id']);
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'item_id']);
            $table->index(['property_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_balances');
    }
};
