<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('adjustment_number', 20);
            $table->char('location_id', 26);
            $table->string('adjustment_type', 30);
            $table->string('status', 30)->default('draft');
            $table->text('reason');
            $table->char('submitted_by', 26)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->char('approved_by', 26)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->char('rejected_by', 26)->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'adjustment_number']);
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'adjustment_type']);
            $table->index(['property_id', 'location_id', 'status']);
            $table->index(['property_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};
