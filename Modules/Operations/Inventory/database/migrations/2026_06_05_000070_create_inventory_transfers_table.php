<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('transfer_number', 20);
            $table->char('from_location_id', 26);
            $table->char('to_location_id', 26);
            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();
            $table->char('requested_by', 26);
            $table->char('approved_by', 26)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->char('completed_by', 26)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->char('cancelled_by', 26)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('from_location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $table->foreign('to_location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'transfer_number']);
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'from_location_id', 'status']);
            $table->index(['property_id', 'to_location_id', 'status']);
            $table->index(['property_id', 'requested_by']);
            $table->index(['property_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
