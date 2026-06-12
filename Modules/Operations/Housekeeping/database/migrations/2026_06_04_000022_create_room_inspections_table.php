<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('room_inspections');
        
        Schema::create('room_inspections', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('room_id', 26);
            $table->char('cleaning_task_id', 26)->nullable();
            $table->string('inspection_type', 50)->nullable();
            $table->string('status', 50)->default('pending');
            $table->string('inspection_severity', 50)->nullable();
            $table->char('supervisor_id', 26)->nullable(); // Allow null for tests
            $table->integer('score')->nullable();
            $table->integer('max_score')->nullable();
            $table->boolean('is_passed')->default(false);
            $table->timestamp('inspected_at')->nullable();
            $table->text('remarks')->nullable();
            $table->text('notes')->nullable();
            $table->json('results')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['property_id', 'is_passed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_inspections');
    }
};