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
            $table->char('task_id', 26);
            $table->char('property_id', 26);
            $table->string('assignee_type', 50);
            $table->char('assignee_id', 26);
            $table->index(['assignee_type', 'assignee_id']);
            $table->timestamps();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            
            $table->unique(['task_id', 'assignee_type', 'assignee_id'], 'task_assignments_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignments');
    }
};
