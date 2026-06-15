<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('bank_accounts', 'branch_name')) {
                $table->string('branch_name', 255)->nullable()->after('bank_name');
            }
            if (!Schema::hasColumn('bank_accounts', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('currency_code');
            }
        });

        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('name', 255);
            $table->string('currency_code', 10)->default('IDR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->unique(['property_id', 'name']);
        });

        Schema::create('fund_transfers', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('transfer_number', 50);
            $table->char('source_bank_account_id', 26)->nullable();
            $table->char('destination_bank_account_id', 26)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('status', 30)->default('DRAFT');
            $table->date('transfer_date');
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('source_bank_account_id')->references('id')->on('bank_accounts')->restrictOnDelete();
            $table->foreign('destination_bank_account_id')->references('id')->on('bank_accounts')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['property_id', 'transfer_number']);
        });

        Schema::create('payment_batches', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('batch_number', 50);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('status', 30)->default('DRAFT');
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->unique(['property_id', 'batch_number']);
        });

        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('vendor_id', 26);
            $table->char('payment_batch_id', 26)->nullable();
            $table->char('bank_account_id', 26);
            $table->string('payment_number', 50);
            $table->date('payment_date');
            $table->decimal('total_amount', 14, 2);
            $table->string('status', 30)->default('DRAFT');
            $table->text('remarks')->nullable();

            $table->char('approved_by', 26)->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete();
            $table->foreign('payment_batch_id')->references('id')->on('payment_batches')->nullOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'payment_number']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('vendor_payment_id', 26);
            $table->char('ap_invoice_id', 26);
            $table->decimal('allocated_amount', 14, 2);
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('vendor_payment_id')->references('id')->on('vendor_payments')->cascadeOnDelete();
            $table->foreign('ap_invoice_id')->references('id')->on('ap_invoices')->restrictOnDelete();

            $table->unique(['vendor_payment_id', 'ap_invoice_id'], 'uk_allocation_payment_invoice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('vendor_payments');
        Schema::dropIfExists('payment_batches');
        Schema::dropIfExists('fund_transfers');
        Schema::dropIfExists('cash_accounts');
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['branch_name', 'is_default']);
        });
    }
};
