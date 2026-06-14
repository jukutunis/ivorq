<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('task_type', 50)->nullable();
            $table->string('source_module', 50)->nullable();
            $table->char('parent_task_id', 26)->nullable();
            $table->string('taskable_type', 50)->nullable();
            $table->char('taskable_id', 26)->nullable();
            $table->index(['taskable_type', 'taskable_id']);

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 30)->default('normal');
            $table->string('status', 30)->default('draft');
            $table->dateTime('due_date')->nullable();
            
            $table->text('resolution_note')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->char('deleted_by', 26)->nullable();
            
            $table->index(['property_id', 'status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
