<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('cleaning_task_id', 26);
            $table->char('user_id', 26);
            $table->char('department_id', 26);
            $table->char('assigned_by', 26)->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('cleaning_task_id')->references('id')->on('cleaning_tasks')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['property_id', 'cleaning_task_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignments');
    }
};
