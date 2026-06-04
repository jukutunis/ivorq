<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('zone_code', 20);
            $table->string('zone_name');
            $table->string('zone_type', 30);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->smallInteger('priority')->default(3);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();

            $table->unique(['property_id', 'zone_code']);
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'zone_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
