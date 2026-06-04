<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only log — no updated_at, no soft delete, no created_by/updated_by.
        // property_id is stored for multi-tenancy queries but not FK'd (log records
        // must survive property lifecycle changes).
        Schema::create('room_status_histories', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26)->nullable();
            $table->char('room_id', 26)->nullable();
            $table->string('status_field', 20);       // 'cleanliness' | 'occupancy'
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('action', 50);
            $table->text('remarks')->nullable();
            $table->char('performed_by', 26)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('room_id')
                ->references('id')->on('rooms')
                ->nullOnDelete();

            $table->foreign('performed_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['property_id', 'room_id']);
            $table->index(['property_id', 'room_id', 'status_field']);
            $table->index(['property_id', 'status_field', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_status_histories');
    }
};
