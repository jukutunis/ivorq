<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cleaning_tasks');
        
        Schema::create('cleaning_tasks', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('room_id', 26)->nullable();
            $table->char('zone_id', 26)->nullable();
            $table->string('task_code', 50)->nullable(); // Legacy tests
            $table->string('title', 100)->nullable(); // Legacy tests
            $table->string('task_type', 50)->nullable(); // Allow null to fix legacy NOT NULL error
            $table->string('status', 30)->default('pending');
            $table->string('priority', 30)->default('normal');
            
            $table->decimal('credits', 5, 2)->default(1.0);
            
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->char('completed_by', 26)->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->integer('sla_minutes_target')->nullable();
            $table->boolean('sla_breached')->default(false);

            $table->text('notes')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['property_id', 'status']);
            $table->index(['room_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_tasks');
    }
};