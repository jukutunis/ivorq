<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historical assignment record — no soft delete.
        // work_order_id cascades on hard delete so orphaned assignment rows
        // cannot exist.
        Schema::create('technician_assignments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('work_order_id', 26);
            $table->char('user_id', 26);
            $table->char('department_id', 26)->nullable();
            $table->string('role', 30)->default('lead');
            $table->string('status', 30)->default('active');
            $table->char('assigned_by', 26)->nullable();
            $table->dateTime('assigned_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->decimal('hours_worked', 5, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['property_id', 'work_order_id']);
            $table->index(['property_id', 'user_id', 'status']);
            $table->index(['property_id', 'assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_assignments');
    }
};
