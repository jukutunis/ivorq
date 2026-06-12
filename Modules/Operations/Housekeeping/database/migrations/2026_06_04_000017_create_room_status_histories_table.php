<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('room_status_histories');
        
        Schema::create('room_status_histories', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('room_id', 26)->nullable();
            $table->string('status_field', 50); // cleanliness, occupancy
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('action');
            $table->char('performed_by', 26)->nullable();
            $table->string('remarks')->nullable();
            $table->timestamp('created_at');
            
            $table->index(['property_id', 'room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_status_histories');
    }
};