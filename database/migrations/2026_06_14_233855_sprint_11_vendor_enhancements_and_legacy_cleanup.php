<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop legacy tables
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('vendor_invoice_lines');
        Schema::dropIfExists('vendor_invoices');

        // 2. Enhance vendors table
        Schema::table('vendors', function (Blueprint $table) {
            if (!Schema::hasColumn('vendors', 'payment_term_days')) {
                $table->integer('payment_term_days')->default(0)->after('default_currency_code');
            }
            if (!Schema::hasColumn('vendors', 'credit_limit')) {
                $table->decimal('credit_limit', 15, 2)->default(0)->after('payment_term_days');
            }
            if (!Schema::hasColumn('vendors', 'tax_number')) {
                $table->string('tax_number', 100)->nullable()->after('tax_id');
            }
            if (!Schema::hasColumn('vendors', 'contact_person')) {
                $table->string('contact_person', 255)->nullable()->after('tax_number');
            }
            if (!Schema::hasColumn('vendors', 'email')) {
                $table->string('email', 255)->nullable()->after('contact_person');
            }
            if (!Schema::hasColumn('vendors', 'phone')) {
                $table->string('phone', 50)->nullable()->after('email');
            }
        });

        // 3. Enhance purchase_requests table with approval governance
        Schema::table('purchase_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_requests', 'approved_by')) {
                $table->char('approved_by', 26)->nullable()->after('status');
                $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('purchase_requests', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (!Schema::hasColumn('purchase_requests', 'rejected_by')) {
                $table->char('rejected_by', 26)->nullable()->after('approved_at');
                $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('purchase_requests', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            }
            if (!Schema::hasColumn('purchase_requests', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropColumn([
                'approved_by',
                'approved_at',
                'rejected_by',
                'rejected_at',
                'rejection_reason',
            ]);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'payment_term_days',
                'credit_limit',
                'tax_number',
                'contact_person',
                'email',
                'phone',
            ]);
        });
    }
};
