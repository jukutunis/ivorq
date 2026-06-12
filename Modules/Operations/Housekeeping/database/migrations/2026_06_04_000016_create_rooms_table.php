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
            
            // Housekeeping Operational States
            $table->string('cleanliness_status', 30)->default('dirty'); // clean, dirty, inspected, pickup, ooo, oos
            $table->string('readiness_state', 30)->default('waiting_inspection'); // ready_for_sale, ready_for_arrival, ready_for_vip, waiting_inspection, waiting_engineering, waiting_amenities, blocked
            
            // Guest-centric Modifiers
            $table->string('occupancy_status', 30)->nullable(); // arrival, stayover, departure, vacant
            $table->boolean('is_dnd')->default(false);
            $table->boolean('turndown_required')->default(false);
            $table->boolean('is_vip')->default(false);
            
            // Workload
            $table->decimal('credits', 5, 2)->default(1.0);

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'room_number']);
            $table->index(['property_id', 'cleanliness_status']);
            $table->index(['property_id', 'readiness_state']);
            $table->index(['property_id', 'occupancy_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};