<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ap_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties');
            $table->foreignUlid('vendor_id')->constrained('vendors');
            
            $table->string('invoice_type');
            $table->string('status')->default('draft');
            $table->string('vendor_invoice_number');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            
            $table->decimal('subtotal_amount', 15, 3)->default(0);
            $table->decimal('tax_amount', 15, 3)->default(0);
            $table->decimal('grand_total_amount', 15, 3)->default(0);
            
            $table->text('remarks')->nullable();
            
            $table->foreignUlid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            
            $table->foreignUlid('rejected_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            
            $table->foreignUlid('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            
            $table->foreignUlid('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            
            $table->foreignUlid('created_by')->nullable()->constrained('users');
            $table->foreignUlid('updated_by')->nullable()->constrained('users');
            $table->foreignUlid('deleted_by')->nullable()->constrained('users');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Unique constraint to prevent duplicate invoices from same vendor in the same property
            $table->unique(['property_id', 'vendor_id', 'vendor_invoice_number'], 'ap_invoices_unique_vendor_invoice');
        });

        Schema::create('ap_invoice_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->constrained('ap_invoices')->cascadeOnDelete();
            
            $table->foreignUlid('receipt_line_id')->nullable()->constrained('receiving_lines')->nullOnDelete();
            
            $table->string('description');
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 3);
            
            $table->decimal('subtotal_amount', 15, 3);
            $table->decimal('tax_amount', 15, 3)->default(0);
            $table->decimal('total_amount', 15, 3);
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_invoice_lines');
        Schema::dropIfExists('ap_invoices');
    }
};
