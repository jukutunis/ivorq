<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receiving_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('receiving_document_id')->constrained('receiving_documents')->cascadeOnDelete();
            $table->foreignUlid('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->restrictOnDelete();
            
            $table->char('inventory_item_id', 26)->nullable();
            $table->char('inventory_unit_id', 26)->nullable();
            $table->char('destination_location_id', 26)->nullable();
            
            $table->string('description');
            $table->decimal('received_quantity', 15, 2);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('line_total', 15, 2);
            
            $table->string('serial_number')->nullable();
            $table->string('imei')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('lot_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('manufacture_date')->nullable();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->index(['receiving_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_lines');
    }
};
