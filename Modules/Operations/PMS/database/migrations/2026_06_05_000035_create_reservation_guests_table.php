<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivot: supports multi-guest reservations (additional guests beyond the primary)
        Schema::create('reservation_guests', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('reservation_id', 26);
            $table->char('guest_id', 26);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('reservation_id')->references('id')->on('reservations')->cascadeOnDelete();
            $table->foreign('guest_id')->references('id')->on('guests')->restrictOnDelete();

            $table->unique(['reservation_id', 'guest_id']);
            $table->index(['property_id', 'reservation_id']);
            $table->index(['property_id', 'guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_guests');
    }
};
