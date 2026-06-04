<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);

            $table->string('work_order_number', 20);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('work_order_type', 30);
            $table->smallInteger('priority')->unsigned()->default(3);
            $table->string('status', 30)->default('pending');

            // Location — room_id / zone_id are nullable cross-module FKs;
            // location_description is a free-form fallback when neither applies.
            $table->string('location_type', 30)->nullable();
            $table->char('room_id', 26)->nullable();
            $table->char('zone_id', 26)->nullable();
            $table->string('location_description', 255)->nullable();

            // asset_description stores a free-text description of the equipment under work.
            // TODO: replace with asset_id FK → assets.id once the Asset Register module ships.
            $table->string('asset_description', 255)->nullable();

            // SLA target (Blueprint R3): hours within which the WO must be completed.
            // Null = no SLA defined for this work order.
            $table->decimal('sla_hours', 5, 2)->nullable();

            $table->decimal('estimated_hours', 5, 2)->nullable();
            $table->decimal('actual_hours', 5, 2)->nullable();
            $table->dateTime('due_date')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->char('completed_by', 26)->nullable();

            $table->text('on_hold_reason')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->char('cancelled_by', 26)->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->char('approved_by', 26)->nullable();
            $table->dateTime('approved_at')->nullable();

            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
            $table->foreign('zone_id')->references('id')->on('zones')->nullOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'work_order_number']);
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'work_order_type', 'status']);
            $table->index(['property_id', 'priority', 'status']);
            $table->index(['property_id', 'due_date']);
            $table->index(['property_id', 'room_id', 'status']);
            $table->index(['property_id', 'zone_id', 'status']);
            $table->index(['property_id', 'completed_at']);
            $table->index('completed_by');
            $table->index('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
