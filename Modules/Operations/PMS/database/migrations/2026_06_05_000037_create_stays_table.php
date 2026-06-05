<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stays', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('reservation_id', 26);

            // The actual physical room occupied — resolved at check-in
            $table->char('room_id', 26);

            // Primary occupying guest for this stay leg
            $table->char('guest_id', 26);

            $table->string('status', 30)->default('reserved'); // StayStatusEnum

            // Temporal tracking
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('expected_departure_at');
            $table->dateTime('check_out_at')->nullable();

            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('reservation_id')->references('id')->on('reservations')->restrictOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->restrictOnDelete();
            $table->foreign('guest_id')->references('id')->on('guests')->restrictOnDelete();

            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'room_id', 'status']);
            $table->index(['property_id', 'guest_id']);
            $table->index(['property_id', 'reservation_id']);
            $table->index(['property_id', 'check_in_at']);
            $table->index(['property_id', 'expected_departure_at']);
            // Optimised for "is this room occupied right now?" queries
            $table->index(['room_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stays');
    }
};
