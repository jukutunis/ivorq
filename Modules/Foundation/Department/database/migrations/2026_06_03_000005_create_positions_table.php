<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('department_id', 26);
            $table->string('name');
            $table->string('code', 20);
            $table->smallInteger('level')->default(1);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->index(['property_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
