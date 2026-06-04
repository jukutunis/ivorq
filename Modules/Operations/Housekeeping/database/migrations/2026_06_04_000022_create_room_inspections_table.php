<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_inspections', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('room_id', 26);
            $table->char('cleaning_task_id', 26)->nullable();
            $table->char('inspector_id', 26)->nullable();
            $table->string('inspection_type', 30);
            $table->string('status', 30)->default('pending');
            $table->string('inspection_severity', 20)->nullable();
            $table->text('remarks')->nullable();
            $table->dateTime('inspected_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->restrictOnDelete();
            $table->foreign('cleaning_task_id')->references('id')->on('cleaning_tasks')->restrictOnDelete();
            $table->foreign('inspector_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['property_id', 'room_id', 'status']);
            $table->index(['property_id', 'status']);
            $table->index('inspector_id');
            $table->index(['property_id', 'inspection_severity']);
            $table->index(['property_id', 'status', 'inspection_severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_inspections');
    }
};
