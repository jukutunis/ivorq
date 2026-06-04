<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('zone_id', 26)->nullable();
            $table->string('room_number', 20);
            $table->string('room_name')->nullable();
            $table->string('room_type', 30);
            $table->string('floor', 10)->nullable();
            $table->string('building', 100)->nullable();

            // v1.1: two independent status dimensions
            $table->string('cleanliness_status', 30)->default('dirty');
            $table->string('occupancy_status', 30)->nullable(); // null = untracked (PMS not yet active)

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('zone_id')->references('id')->on('zones')->restrictOnDelete();

            $table->unique(['property_id', 'room_number']);
            $table->index(['property_id', 'cleanliness_status']);
            $table->index(['property_id', 'occupancy_status']);
            $table->index(['property_id', 'zone_id']);
            $table->index(['property_id', 'room_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
