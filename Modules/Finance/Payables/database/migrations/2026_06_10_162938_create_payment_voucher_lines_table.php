<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_voucher_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('payment_voucher_id')->index();
            $table->ulid('account_payable_id')->index();
            
            // Core
            $table->decimal('amount_paid', 15, 2);
            $table->text('remarks')->nullable();

            // Snapshots per CTO request
            $table->string('ap_payable_no', 50);
            $table->decimal('ap_original_amount', 15, 2);
            $table->decimal('ap_outstanding_before', 15, 2);
            $table->decimal('ap_outstanding_after', 15, 2)->nullable(); // Filled upon Posting

            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Can a single AP be paid multiple times in the same voucher? Usually no. 
            // We'll leave it without unique constraint on AP + Voucher just in case, but application logic will group them.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_voucher_lines');
    }
};
