<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_invoice_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('vendor_invoice_id')->index();
            $table->ulid('purchase_order_line_id')->nullable()->index();
            $table->ulid('goods_receipt_line_id')->nullable()->index();
            $table->ulid('inventory_item_id')->nullable()->index();
            $table->string('description');
            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_invoice_lines');
    }
};
