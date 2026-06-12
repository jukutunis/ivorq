<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('task_assignments');
        
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26)->nullable(); // Added for legacy tests
            $table->char('cleaning_task_id', 26);
            $table->char('user_id', 26)->nullable(); // Legacy tests use user_id
            $table->char('attendant_id', 26)->nullable(); // V2.6
            $table->char('department_id', 26)->nullable(); // Legacy tests
            $table->string('status', 30)->default('active'); // Legacy tests
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable(); // Legacy tests
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignments');
    }
};