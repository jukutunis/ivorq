<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('housekeeping_checklists');
        Schema::dropIfExists('cleaning_checklists');
        
        Schema::create('cleaning_checklists', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('task_type', 50)->nullable(); // Applies to specific task types
            $table->integer('total_points')->default(0);
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['property_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_checklists');
    }
};