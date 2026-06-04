<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preventive_maintenances', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('pm_code', 20);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('frequency', 30);
            // Used when frequency = 'custom'; stores the interval in days.
            $table->smallInteger('frequency_days')->unsigned()->nullable();
            $table->string('status', 30)->default('active');
            $table->char('room_id', 26)->nullable();
            $table->char('zone_id', 26)->nullable();
            $table->string('asset_description', 255)->nullable();
            $table->decimal('estimated_hours', 5, 2)->nullable();
            $table->char('department_id', 26)->nullable();
            $table->dateTime('last_run_at')->nullable();
            $table->dateTime('next_due_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
            $table->foreign('zone_id')->references('id')->on('zones')->nullOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();

            $table->unique(['property_id', 'pm_code']);
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'frequency', 'status']);
            $table->index(['property_id', 'next_due_at']);
            $table->index(['property_id', 'room_id']);
            $table->index(['property_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preventive_maintenances');
    }
};
