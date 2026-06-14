<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('inventory_counts');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->string('status')->default('draft');
            $table->ulid('location_id')->nullable()->index();
            $table->ulid('counted_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
