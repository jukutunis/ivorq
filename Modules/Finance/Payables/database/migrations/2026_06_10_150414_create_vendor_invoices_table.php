<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vendor_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('vendor_id')->index();
            $table->ulid('purchase_order_id')->nullable()->index();
            $table->ulid('goods_receipt_id')->nullable()->index();
            
            $table->string('invoice_number');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            
            $table->string('status');
            
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            
            $table->text('remarks')->nullable();
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['vendor_id', 'invoice_number', 'property_id'], 'vendor_invoice_number_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_invoices');
    }
};
