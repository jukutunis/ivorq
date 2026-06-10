<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('three_way_match_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('three_way_match_id')->index();
            $table->ulid('vendor_invoice_line_id')->index();
            $table->ulid('purchase_order_line_id')->nullable()->index();
            $table->ulid('goods_receipt_line_id')->nullable()->index();
            $table->ulid('inventory_item_id')->nullable()->index();
            
            $table->decimal('po_quantity', 15, 4)->default(0);
            $table->decimal('po_price', 15, 2)->default(0);
            
            $table->decimal('grn_quantity', 15, 4)->default(0);
            
            $table->decimal('invoice_quantity', 15, 4)->default(0);
            $table->decimal('invoice_price', 15, 2)->default(0);
            
            $table->decimal('quantity_variance', 15, 4)->default(0);
            $table->decimal('price_variance', 15, 2)->default(0);
            $table->decimal('amount_variance', 15, 2)->default(0);
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('three_way_match_lines');
    }
};
