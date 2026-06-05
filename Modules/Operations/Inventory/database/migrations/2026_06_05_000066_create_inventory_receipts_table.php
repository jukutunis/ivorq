<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_receipts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('receipt_number', 20);
            $table->string('supplier_name', 255)->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->string('status', 30)->default('draft');
            $table->dateTime('received_at')->nullable();
            $table->text('remarks')->nullable();
            $table->char('posted_by', 26)->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->char('cancelled_by', 26)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'receipt_number']);
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'supplier_name']);
            $table->index(['property_id', 'received_at']);
            $table->index(['property_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_receipts');
    }
};
