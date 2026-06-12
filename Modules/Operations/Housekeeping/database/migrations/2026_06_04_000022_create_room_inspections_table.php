<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_inspections', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('room_id', 26);
            $table->char('cleaning_task_id', 26)->nullable();
            $table->char('supervisor_id', 26);
            $table->integer('score')->nullable();
            $table->integer('max_score')->nullable();
            $table->boolean('is_passed')->default(false);
            $table->text('notes')->nullable();
            $table->json('results')->nullable(); // JSON payload of checklist item answers
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