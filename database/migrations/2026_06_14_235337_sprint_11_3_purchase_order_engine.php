<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_for_quotations', function (Blueprint $table) {
            $table->char('awarded_vendor_id', 26)->nullable();
            $table->char('awarded_quotation_id', 26)->nullable();
            $table->timestamp('awarded_at')->nullable();
            $table->char('awarded_by', 26)->nullable();

            $table->foreign('awarded_vendor_id')->references('id')->on('vendors')->nullOnDelete();
            $table->foreign('awarded_quotation_id')->references('id')->on('vendor_quotations')->nullOnDelete();
            $table->foreign('awarded_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->char('approved_by', 26)->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->renameColumn('quantity_ordered', 'ordered_quantity');
            $table->renameColumn('quantity_received', 'received_quantity');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->decimal('invoiced_quantity', 14, 3)->default(0)->after('received_quantity');
            $table->decimal('receiving_tolerance_percent', 5, 2)->default(0)->after('invoiced_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropColumn('invoiced_quantity');
            $table->dropColumn('receiving_tolerance_percent');
            $table->renameColumn('ordered_quantity', 'quantity_ordered');
            $table->renameColumn('received_quantity', 'quantity_received');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved_by', 'approved_at']);
        });

        Schema::table('request_for_quotations', function (Blueprint $table) {
            $table->dropForeign(['awarded_vendor_id']);
            $table->dropForeign(['awarded_quotation_id']);
            $table->dropForeign(['awarded_by']);
            $table->dropColumn(['awarded_vendor_id', 'awarded_quotation_id', 'awarded_at', 'awarded_by']);
        });
    }
};
