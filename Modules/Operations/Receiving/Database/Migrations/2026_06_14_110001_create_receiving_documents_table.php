<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receiving_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignUlid('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignUlid('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            
            $table->string('grn_number')->unique();
            $table->string('vendor_delivery_no')->nullable();
            
            $table->string('status')->default(ReceivingDocumentStatusEnum::Draft->value);
            $table->timestamp('received_at')->nullable();
            $table->foreignUlid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->char('deleted_by', 26)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_documents');
    }
};
