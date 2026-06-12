<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_status_histories', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('room_id', 26);
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->string('reason')->nullable();
            $table->char('changed_by', 26)->nullable();
            $table->timestamps();
            
            $table->index(['room_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_status_histories');
    }
};