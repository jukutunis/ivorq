<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Operations\Purchasing\Enums\ReceiptSourceType;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_receipt_source_authorities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->string('source_type', 48);
            $table->string('binding_reason', 80);
            $table->timestamps();

            $table->unique('purchase_order_id', 'purchase_order_receipt_source_authority_unique');
            $table->index(['property_id', 'source_type'], 'purchase_order_receipt_source_authority_scope');
        });

        DB::table('purchase_orders')
            ->orderBy('id')
            ->select(['id', 'property_id'])
            ->chunk(200, function ($purchaseOrders): void {
                foreach ($purchaseOrders as $purchaseOrder) {
                    $hasControlled = DB::table('goods_receipts')
                        ->where('property_id', $purchaseOrder->property_id)
                        ->where('purchase_order_id', $purchaseOrder->id)
                        ->exists();
                    $hasLegacy = DB::table('receiving_documents')
                        ->where('property_id', $purchaseOrder->property_id)
                        ->where('purchase_order_id', $purchaseOrder->id)
                        ->exists();

                    if ($hasControlled && $hasLegacy) {
                        throw new RuntimeException(
                            "RECEIPT_SOURCE_CONFLICT_REQUIRES_MANUAL_RESOLUTION: {$purchaseOrder->id}"
                        );
                    }

                    $sourceType = $hasLegacy
                        ? ReceiptSourceType::LegacyReceivingDocument
                        : ReceiptSourceType::InventoryGoodsReceipt;
                    $reason = $hasLegacy
                        ? 'legacy_evidence_precedes_source_governance'
                        : ($hasControlled ? 'controlled_evidence_present' : 'controlled_default_no_historical_evidence');

                    DB::table('purchase_order_receipt_source_authorities')->insert([
                        'id' => (string) Str::ulid(),
                        'property_id' => $purchaseOrder->property_id,
                        'purchase_order_id' => $purchaseOrder->id,
                        'source_type' => $sourceType->value,
                        'binding_reason' => $reason,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_receipt_source_authorities');
    }
};
