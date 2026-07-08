<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineering_room_availability_blocks', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('room_id', 26);
            $table->string('block_status', 20);
            $table->string('block_reason', 255);
            $table->string('source_type', 100)->nullable();
            $table->char('source_id', 26)->nullable();
            $table->dateTime('started_at');
            $table->char('started_by', 26);
            $table->dateTime('released_at')->nullable();
            $table->char('released_by', 26)->nullable();
            $table->string('release_reason', 255)->nullable();
            $table->string('idempotency_key', 120);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->restrictOnDelete();
            $table->foreign('started_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('released_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'idempotency_key'], 'eng_room_availability_blocks_idem_unique');
            $table->index(['property_id', 'room_id', 'block_status'], 'eng_room_availability_blocks_room_status_idx');
            $table->index(['property_id', 'source_type', 'source_id'], 'eng_room_availability_blocks_source_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX eng_room_availability_blocks_one_active_room
                ON engineering_room_availability_blocks (property_id, room_id)
                WHERE block_status = 'ACTIVE'
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS eng_room_availability_blocks_one_active_room');
        }

        Schema::dropIfExists('engineering_room_availability_blocks');
    }
};
