<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Operations\Purchasing\Enums\ReceiptSourceType;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->string('receipt_source_type', 48)->nullable()->after('goods_receipt_id');
            $table->index(
                ['receipt_source_type', 'goods_receipt_id'],
                'vendor_invoices_receipt_source_lookup'
            );
        });

        Schema::table('vendor_invoice_lines', function (Blueprint $table) {
            $table->char('receipt_commercial_evidence_hash', 64)
                ->nullable()
                ->after('goods_receipt_line_id');
        });

        DB::table('vendor_invoices')
            ->whereNotNull('goods_receipt_id')
            ->orderBy('id')
            ->select(['id', 'goods_receipt_id'])
            ->chunk(200, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $legacyMatches = DB::table('receiving_documents')
                        ->where('id', $invoice->goods_receipt_id)
                        ->count();
                    $controlledMatches = DB::table('goods_receipts')
                        ->where('id', $invoice->goods_receipt_id)
                        ->count();

                    if ($legacyMatches !== 1 || $controlledMatches !== 0) {
                        throw new RuntimeException(
                            "Supplier invoice receipt source cannot be proven uniquely: {$invoice->id}"
                        );
                    }

                    DB::table('vendor_invoices')
                        ->where('id', $invoice->id)
                        ->update([
                            'receipt_source_type' => ReceiptSourceType::LegacyReceivingDocument->value,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('vendor_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('receipt_commercial_evidence_hash');
        });

        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->dropIndex('vendor_invoices_receipt_source_lookup');
            $table->dropColumn('receipt_source_type');
        });
    }
};
