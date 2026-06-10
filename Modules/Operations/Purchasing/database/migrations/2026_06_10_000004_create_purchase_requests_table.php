<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('request_no', 50);
            $table->char('department_id', 26);
            $table->char('requester_id', 26);
            $table->date('required_date');
            $table->string('currency_code', 10)->default('IDR');
            $table->decimal('exchange_rate', 14, 4)->default(1);
            $table->decimal('estimated_total', 14, 2)->default(0);
            $table->string('status', 30)->default('Draft');
            $table->text('remarks')->nullable();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->foreign('requester_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'request_no']);
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'department_id']);
            $table->index(['property_id', 'requester_id']);
            $table->index(['property_id', 'required_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};
