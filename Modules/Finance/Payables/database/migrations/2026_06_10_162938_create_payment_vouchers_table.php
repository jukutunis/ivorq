<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_vouchers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('vendor_id')->index();
            $table->string('voucher_no', 50)->index();
            $table->date('payment_date');
            $table->string('payment_method', 50);
            $table->string('reference_no')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('status', 50)->index();
            $table->text('remarks')->nullable();

            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Unique voucher_no per property
            $table->unique(['property_id', 'voucher_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_vouchers');
    }
};
