<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_requests', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('request_number', 20);
            $table->char('work_order_id', 26)->nullable();
            $table->char('requester_id', 26);
            $table->char('department_id', 26)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('pending');
            $table->smallInteger('priority')->unsigned()->default(3);
            $table->dateTime('required_by')->nullable();
            $table->char('approved_by', 26)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->char('rejected_by', 26)->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->dateTime('fulfilled_at')->nullable();
            $table->char('fulfilled_by', 26)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->foreign('requester_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('fulfilled_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'request_number']);
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'work_order_id']);
            $table->index(['property_id', 'requester_id']);
            $table->index(['property_id', 'priority', 'status']);
            $table->index(['property_id', 'required_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_requests');
    }
};
