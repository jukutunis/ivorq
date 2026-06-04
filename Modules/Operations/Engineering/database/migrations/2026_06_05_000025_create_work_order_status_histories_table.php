<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit log — no soft delete, no updated_by.
        // Rows are inserted once and never mutated.
        // work_order_id cascades on hard delete; property_id is kept for
        // multi-tenancy queries but survives property lifecycle changes (no FK).
        Schema::create('work_order_status_histories', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('work_order_id', 26);
            $table->string('from_status', 30)->nullable(); // null on initial creation record
            $table->string('to_status', 30);
            $table->text('remarks')->nullable();
            $table->char('changed_by', 26)->nullable();
            $table->dateTime('changed_at');
            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')
                ->references('id')->on('work_orders')
                ->cascadeOnDelete();

            $table->foreign('changed_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['property_id', 'work_order_id']);
            $table->index(['property_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_status_histories');
    }
};
