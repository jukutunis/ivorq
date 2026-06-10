<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts_payables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('vendor_id')->index();
            $table->ulid('vendor_invoice_id')->unique();
            $table->string('payable_no', 50)->index();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('currency_code', 3)->default('IDR');
            $table->decimal('exchange_rate', 15, 4)->default(1.0000);
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('outstanding_amount', 15, 2)->default(0);
            $table->string('status', 50)->index();
            $table->text('remarks')->nullable();

            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_payables');
    }
};
