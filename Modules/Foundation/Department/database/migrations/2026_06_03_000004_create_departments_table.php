<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('name');
            $table->string('code', 20);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->unique(['property_id', 'code']);
            $table->index(['property_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
