<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Generated task instances — historical record, no soft delete.
        // Cascades on PM hard delete; work_order_id is nullable (WO may not
        // have been created yet, or was never generated for this occurrence).
        Schema::create('preventive_maintenance_tasks', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('preventive_maintenance_id', 26);
            $table->char('work_order_id', 26)->nullable();
            $table->dateTime('scheduled_date');
            $table->string('status', 30)->default('scheduled');
            $table->dateTime('completed_at')->nullable();
            $table->char('completed_by', 26)->nullable();
            $table->text('remarks')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('preventive_maintenance_id')
                ->references('id')->on('preventive_maintenances')
                ->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['property_id', 'preventive_maintenance_id', 'status']);
            $table->index(['property_id', 'scheduled_date', 'status']);
            $table->index(['property_id', 'status']);
            $table->index('completed_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preventive_maintenance_tasks');
    }
};
