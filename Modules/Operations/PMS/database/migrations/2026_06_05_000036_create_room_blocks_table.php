<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_blocks', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('room_id', 26);

            // OOO = Out Of Order (room cannot be sold / used at all)
            // OOS = Out Of Service (temporarily unavailable but may be sold with caution)
            $table->string('block_type', 30);    // RoomBlockTypeEnum: out_of_order | out_of_service
            $table->string('status', 30)->default('active'); // RoomBlockStatusEnum: active | released | expired
            $table->string('reason', 30)->nullable();        // RoomBlockReasonEnum

            $table->text('notes')->nullable();

            // Block period — end_at may be null for indefinite OOO
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();

            // Release tracking
            $table->dateTime('released_at')->nullable();
            $table->char('released_by', 26)->nullable();

            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->restrictOnDelete();
            $table->foreign('released_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['property_id', 'room_id', 'status']);
            $table->index(['property_id', 'status', 'start_at', 'end_at']);
            $table->index(['property_id', 'block_type', 'status']);
            // Compound index optimised for overlap detection: same room, same period, active
            $table->index(['room_id', 'status', 'start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_blocks');
    }
};
