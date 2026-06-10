<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('three_way_matches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('vendor_invoice_id')->unique();
            $table->ulid('purchase_order_id')->nullable()->index();
            $table->ulid('goods_receipt_id')->nullable()->index();
            
            $table->string('status', 50)->index();
            $table->string('exception_code', 100)->nullable();
            
            $table->decimal('total_quantity_variance', 15, 4)->default(0);
            $table->decimal('total_price_variance', 15, 2)->default(0);
            $table->decimal('total_amount_variance', 15, 2)->default(0);
            
            $table->text('remarks')->nullable();
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('three_way_matches');
    }
};
