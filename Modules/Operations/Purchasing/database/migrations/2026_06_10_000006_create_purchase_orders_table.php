<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('po_no', 50);
            $table->char('vendor_id', 26);
            $table->char('purchase_request_id', 26);
            $table->date('issue_date');
            $table->date('expected_delivery_date');
            $table->string('currency_code', 10)->default('IDR');
            $table->decimal('exchange_rate', 14, 4)->default(1);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('received_total', 14, 2)->nullable()->default(0);
            $table->string('status', 30)->default('DRAFT');
            $table->text('remarks')->nullable();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete();
            $table->foreign('purchase_request_id')->references('id')->on('purchase_requests')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'po_no']);
            $table->unique('purchase_request_id'); // BR-007: One PR = One PO
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
