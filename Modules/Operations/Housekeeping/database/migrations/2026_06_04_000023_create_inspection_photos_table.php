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
            $table->char('room_inspection_id', 26);
            $table->string('file_path');
            $table->string('description')->nullable();
            $table->timestamps();
            
            $table->index(['property_id', 'room_inspection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_photos');
    }
};
