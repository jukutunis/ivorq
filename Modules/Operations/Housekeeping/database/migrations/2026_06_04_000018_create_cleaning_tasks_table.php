<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_tasks', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('room_id', 26)->nullable();
            $table->char('zone_id', 26)->nullable();
            $table->string('task_code', 20);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('task_type', 30);
            $table->string('status', 30)->default('pending');
            $table->smallInteger('priority')->unsigned()->default(3);
            $table->smallInteger('estimated_duration_minutes')->unsigned()->nullable();
            $table->dateTime('due_date')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->char('completed_by', 26)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
            $table->foreign('zone_id')->references('id')->on('zones')->restrictOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'task_code']);
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'room_id', 'status']);
            $table->index(['property_id', 'zone_id', 'status']);
            $table->index(['property_id', 'due_date']);
            $table->index(['property_id', 'task_type']);
            $table->index('completed_by');
            $table->index(['property_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_tasks');
    }
};
