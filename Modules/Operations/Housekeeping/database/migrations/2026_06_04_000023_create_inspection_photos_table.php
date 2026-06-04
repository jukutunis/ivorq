<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_photos', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('inspection_id', 26);
            $table->string('file_path', 500);
            $table->string('file_name');
            $table->text('notes')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('inspection_id')->references('id')->on('room_inspections')->restrictOnDelete();

            $table->index('inspection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_photos');
    }
};
