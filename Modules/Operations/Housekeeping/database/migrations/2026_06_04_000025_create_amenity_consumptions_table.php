<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenity_consumptions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('cleaning_task_id', 26);
            $table->char('inventory_item_id', 26); // Links to Inventory Foundation
            $table->integer('quantity');
            $table->string('type', 30)->default('standard'); // standard, extra, damaged
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amenity_consumptions');
    }
};