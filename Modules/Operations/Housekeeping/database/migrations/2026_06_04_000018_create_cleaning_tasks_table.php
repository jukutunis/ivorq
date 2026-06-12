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
            $table->char('room_id', 26)->nullable(); // Could be public area
            $table->char('zone_id', 26)->nullable(); // Public Area
            $table->string('task_type', 50); // departure, stayover, turndown, deep_clean, public_area
            $table->string('status', 30)->default('pending'); // pending, assigned, in_progress, completed, verified
            $table->string('priority', 30)->default('normal'); // normal, rush
            
            $table->decimal('credits', 5, 2)->default(1.0);
            
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();

            // SLA Tracking
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