<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('department_id', 26)->nullable();
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_cross_day')->default(false);
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            
            $table->index(['property_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
