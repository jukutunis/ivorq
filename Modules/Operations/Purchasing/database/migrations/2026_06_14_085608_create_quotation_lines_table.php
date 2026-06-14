<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->foreignUlid('purchase_request_line_id')->nullable()->constrained('purchase_request_lines')->nullOnDelete();
            
            $table->string('item_name');
            $table->string('description')->nullable();
            $table->decimal('quantity', 10, 2);
            $table->string('uom')->nullable();
            
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total_price', 15, 2);
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
    }
};
