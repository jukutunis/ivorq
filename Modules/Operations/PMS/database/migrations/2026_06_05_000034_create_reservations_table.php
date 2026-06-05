<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);

            // Auto-generated per property (e.g. RES-00001); handled by observer/service
            $table->string('reservation_number', 20);

            $table->char('primary_guest_id', 26);
            $table->char('rate_plan_id', 26)->nullable();

            // Occupancy
            $table->tinyInteger('adults')->unsigned()->default(1);
            $table->tinyInteger('children')->unsigned()->default(0);

            // Stay dates
            $table->date('arrival_date');
            $table->date('departure_date');
            // nights is stored (derived, but stored for query performance)
            $table->unsignedSmallInteger('nights')->default(1);

            // Source & type
            $table->string('reservation_source', 30)->default('direct');  // ReservationSourceEnum
            $table->string('status', 30)->default('tentative');            // ReservationStatusEnum

            // Room inventory: reserved by room type code (e.g. 'deluxe', 'standard').
            // Stored as a plain string — no FK to room_types to keep PMS decoupled.
            $table->string('reserved_room_type', 50);
            // assigned_room_id is populated at assignment/check-in time
            $table->char('assigned_room_id', 26)->nullable();

            $table->text('remarks')->nullable();

            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('primary_guest_id')->references('id')->on('guests')->restrictOnDelete();
            $table->foreign('rate_plan_id')->references('id')->on('rate_plans')->nullOnDelete();
            // No FK for reserved_room_type — intentionally decoupled from room_types table.
            $table->foreign('assigned_room_id')->references('id')->on('rooms')->nullOnDelete();

            $table->unique(['property_id', 'reservation_number']);
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'arrival_date', 'departure_date']);
            $table->index(['property_id', 'arrival_date', 'status']);
            $table->index(['property_id', 'departure_date', 'status']);
            $table->index(['property_id', 'reserved_room_type', 'status']);
            $table->index(['property_id', 'primary_guest_id']);
            $table->index(['property_id', 'reservation_source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
