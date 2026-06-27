<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_valuation_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('property_id', 26)->index();
            $table->char('location_id', 26)->index();
            $table->char('item_id', 26)->index();
            $table->bigInteger('last_sequence')->unsigned()->default(0);
            $table->timestamps();

            $table->unique(['property_id', 'location_id', 'item_id'], 'idx_inv_val_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_valuation_sequences');
    }
};
