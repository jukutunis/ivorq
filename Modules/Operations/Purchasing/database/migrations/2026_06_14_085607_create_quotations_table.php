<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignUlid('vendor_id')->constrained('vendors')->cascadeOnDelete();
            
            $table->string('quotation_number')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('freight_amount', 15, 2)->default(0);
            $table->string('currency')->default('USD');
            
            $table->integer('lead_time_days')->nullable();
            $table->string('payment_terms')->nullable();
            
            $table->boolean('is_winner')->default(false);
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Audit columns
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
