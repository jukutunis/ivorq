<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\GoodsReceiptExceptionStatusEnum;
use Modules\Operations\Inventory\Enums\GoodsReceiptLinePostingDispositionEnum;
use Modules\Operations\Inventory\Enums\GoodsReceiptStatusEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum;
use Modules\Operations\Inventory\Models\GoodsReceipt;
use Modules\Operations\Inventory\Models\GoodsReceiptLine;
use Modules\Operations\Inventory\Models\GoodsReceiptLineCommercialEvidence;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Purchasing\Contracts\ProcurementReceiptEvidenceProvider;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Enums\ReceiptSourceType;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;
use RuntimeException;

class ControlledGoodsReceiptPostingService
{
    public function __construct(
        private readonly InventoryLedgerPostingService $ledgerPostingService,
        private readonly SensitiveActionConfirmationService $confirmationService,
        private readonly ProcurementReceiptEvidenceProvider $receiptEvidence,
    ) {}

    public function createDraft(string $purchaseOrderId, array $lines, string $actorId): GoodsReceipt
    {
        return DB::transaction(function () use ($purchaseOrderId, $lines, $actorId) {
            $po = PurchaseOrder::findOrFail($purchaseOrderId);

            if ($po->status !== PurchaseOrderStatusEnum::Approved
                && $po->status !== PurchaseOrderStatusEnum::PartiallyReceived
                && $po->status !== PurchaseOrderStatusEnum::Issued) {
                throw new RuntimeException('Purchase Order must be approved or issued to receive goods.');
            }

            $propertyId = $po->property_id;
            $this->assertControlledReceiptAuthority($propertyId, $po->id);

            $receiptNumber = 'GRN-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
            $now = Carbon::now();

            $receipt = new GoodsReceipt;
            $receipt->setAttribute('id', (string) Str::ulid());
            $receipt->setAttribute('property_id', $propertyId);
            $receipt->setAttribute('purchase_order_id', $purchaseOrderId);
            $receipt->setAttribute('receipt_number', $receiptNumber);
            $receipt->setAttribute('status', GoodsReceiptStatusEnum::Draft->value);
            $receipt->setAttribute('received_by', $actorId);
            $receipt->setAttribute('created_by', $actorId);
            $receipt->setAttribute('created_at', $now);
            $receipt->save();

            foreach ($lines as $line) {
                $poLine = PurchaseOrderLine::findOrFail($line['purchase_order_line_id']);

                if ($poLine->purchase_order_id !== $purchaseOrderId) {
                    throw new RuntimeException('Line does not belong to the specified Purchase Order.');
                }

                $receivedQty = (float) $line['received_quantity'];
                if ($receivedQty <= 0) {
                    throw new RuntimeException('Received quantity must be positive.');
                }

                $remainingQty = (float) $poLine->remaining_quantity;
                if ($receivedQty > $remainingQty) {
                    throw new RuntimeException(
                        "Over-receipt: attempt to receive {$receivedQty} but only {$remainingQty} remaining."
                    );
                }

                $item = InventoryItem::findOrFail($poLine->inventory_item_id);

                $receiptLine = new GoodsReceiptLine;
                $receiptLine->setAttribute('id', (string) Str::ulid());
                $receiptLine->setAttribute('goods_receipt_id', $receipt->id);
                $receiptLine->setAttribute('property_id', $propertyId);
                $receiptLine->setAttribute('purchase_order_line_id', $poLine->id);
                $receiptLine->setAttribute('inventory_item_id', $poLine->inventory_item_id);
                $receiptLine->setAttribute('inventory_location_id', $line['inventory_location_id']);
                $receiptLine->setAttribute('inventory_unit_id', $poLine->unit_id ?? $line['inventory_unit_id']);
                $receiptLine->setAttribute('received_quantity', $receivedQty);
                $receiptLine->setAttribute('posting_disposition', GoodsReceiptLinePostingDispositionEnum::Accepted->value);
                $receiptLine->setAttribute('idempotency_key', $line['idempotency_key'] ?? (string) Str::ulid());
                $receiptLine->setAttribute('created_by', $actorId);
                $receiptLine->setAttribute('created_at', $now);
                $receiptLine->save();
            }

            return $receipt->fresh(['lines']);
        });
    }

    public function resolveCommercialEvidenceHash(GoodsReceipt $receipt, GoodsReceiptLine $line): string
    {
        $poLine = PurchaseOrderLine::findOrFail($line->purchase_order_line_id);
        $po = PurchaseOrder::findOrFail($receipt->purchase_order_id);

        $property = Property::findOrFail($receipt->property_id);

        $fields = [
            'property_id' => $receipt->property_id,
            'goods_receipt_id' => $receipt->id,
            'goods_receipt_line_id' => $line->id,
            'purchase_order_id' => $receipt->purchase_order_id,
            'purchase_order_line_id' => $line->purchase_order_line_id,
            'inventory_item_id' => $line->inventory_item_id,
            'inventory_unit_id' => $line->inventory_unit_id,
            'received_quantity' => $line->received_quantity,
            'property_base_currency_code' => strtoupper((string) $property->currency),
            'purchase_order_currency_code' => strtoupper((string) ($po->currency_code ?? 'IDR')),
            'purchase_order_unit_cost' => (string) $poLine->unit_cost,
            'purchase_order_exchange_rate' => (string) ($po->exchange_rate ?? '0'),
        ];

        ksort($fields);

        $payload = '';
        foreach ($fields as $value) {
            $payload .= (string) $value.'|';
        }

        return hash('sha256', $payload);
    }

    public function createCommercialSnapshot(
        GoodsReceipt $receipt,
        GoodsReceiptLine $line,
        string $actorId,
        Carbon $capturedAt
    ): GoodsReceiptLineCommercialEvidence {
        $poLine = PurchaseOrderLine::findOrFail($line->purchase_order_line_id);
        $po = PurchaseOrder::findOrFail($receipt->purchase_order_id);
        $property = Property::findOrFail($receipt->property_id);

        $hash = $this->resolveCommercialEvidenceHash($receipt, $line);

        $evidence = new GoodsReceiptLineCommercialEvidence;
        $evidence->setAttribute('id', (string) Str::ulid());
        $evidence->setAttribute('property_id', $receipt->property_id);
        $evidence->setAttribute('goods_receipt_id', $receipt->id);
        $evidence->setAttribute('goods_receipt_line_id', $line->id);
        $evidence->setAttribute('purchase_order_id', $receipt->purchase_order_id);
        $evidence->setAttribute('purchase_order_line_id', $line->purchase_order_line_id);
        $evidence->setAttribute('inventory_item_id', $line->inventory_item_id);
        $evidence->setAttribute('inventory_unit_id', $line->inventory_unit_id);
        $evidence->setAttribute('property_base_currency_code_snapshot', strtoupper((string) $property->currency));
        $evidence->setAttribute('purchase_order_currency_code_snapshot', strtoupper((string) ($po->currency_code ?? 'IDR')));
        $evidence->setAttribute('purchase_order_unit_cost_snapshot', (string) $poLine->unit_cost);
        $evidence->setAttribute('purchase_order_exchange_rate_snapshot', (string) ($po->exchange_rate ?? null) !== '' ? (string) ($po->exchange_rate ?? '0') : null);
        $evidence->setAttribute('commercial_evidence_hash', $hash);
        $evidence->setAttribute('captured_at', $capturedAt);
        $evidence->setAttribute('created_by', $actorId);
        $evidence->setAttribute('created_at', $capturedAt);
        $evidence->save();

        return $evidence;
    }

    public function post(GoodsReceipt $receipt, string $actorId): GoodsReceipt
    {
        if ($receipt->status !== GoodsReceiptStatusEnum::ConfirmationPending) {
            throw new RuntimeException('Receipt must be in confirmation pending state to post.');
        }

        $propertyId = $receipt->property_id;
        $po = PurchaseOrder::findOrFail($receipt->purchase_order_id);
        $user = User::findOrFail($actorId);

        if ($user->id === $po->approved_by) {
            throw new RuntimeException('Goods receiver cannot be the Purchase Order approver.');
        }

        $this->confirmationService->requireValidConfirmation(
            $user,
            'inventory-goods-receipt-posting',
            $po->purchaseRequest?->property?->company_id ?? null,
            $propertyId
        );

        $confirmationHash = $this->confirmationService->confirmationMetadataFor(
            $user,
            'inventory-goods-receipt-posting',
            $po->purchaseRequest?->property?->company_id ?? null,
            $propertyId
        )['commercial_evidence_hash'] ?? null;

        return DB::transaction(function () use ($receipt, $po, $propertyId, $actorId, $confirmationHash) {
            $receipt = GoodsReceipt::withoutGlobalScope('property')->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->status !== GoodsReceiptStatusEnum::ConfirmationPending) {
                throw new RuntimeException('Receipt must be in confirmation pending state to post.');
            }

            $this->assertControlledReceiptAuthority($propertyId, $po->id);

            if ($receipt->exceptions()->where('status', GoodsReceiptExceptionStatusEnum::Open->value)->exists()) {
                throw new RuntimeException('Open Goods Receipt exceptions must be resolved before posting.');
            }

            $acceptedLines = GoodsReceiptLine::withoutGlobalScope('property')
                ->where('goods_receipt_id', $receipt->id)
                ->where('posting_disposition', GoodsReceiptLinePostingDispositionEnum::Accepted->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($acceptedLines->isEmpty()) {
                throw new RuntimeException('Goods Receipt requires at least one accepted line for posting.');
            }

            $now = Carbon::now();

            foreach ($acceptedLines as $line) {
                $existingByIdempotency = GoodsReceiptLine::withoutGlobalScope('property')
                    ->where('property_id', $propertyId)
                    ->where('idempotency_key', $line->idempotency_key)
                    ->whereNotNull('stock_movement_id')
                    ->first();

                if ($existingByIdempotency) {
                    continue;
                }

                $poLine = PurchaseOrderLine::findOrFail($line->purchase_order_line_id);

                $remainingQty = (float) $poLine->remaining_quantity;
                $receiptQty = (float) $line->received_quantity;

                if ($receiptQty > $remainingQty) {
                    throw new RuntimeException(
                        "Over-receipt: attempt to receive {$receiptQty} but only {$remainingQty} remaining on PO line."
                    );
                }

                $currentHash = $this->resolveCommercialEvidenceHash($receipt, $line);

                if ($confirmationHash !== null && $currentHash !== $confirmationHash) {
                    throw new RuntimeException(
                        'Commercial evidence has changed since sensitive confirmation. Posting rejected.'
                    );
                }

                $location = InventoryLocation::findOrFail($line->inventory_location_id);

                $correlationId = 'corr-'.(string) Str::ulid();

                $this->createCommercialSnapshot($receipt, $line, $actorId, $now);

                $stockMovement = $this->ledgerPostingService->post([
                    'property_id' => $propertyId,
                    'inventory_item_id' => $line->inventory_item_id,
                    'inventory_location_id' => $line->inventory_location_id,
                    'inventory_unit_id' => $line->inventory_unit_id,
                    'movement_type' => InventoryMovementTypeEnum::GoodsReceipt,
                    'direction' => InventoryMovementDirectionEnum::In,
                    'quantity' => $receiptQty,
                    'source_domain' => 'purchasing',
                    'source_type' => GoodsReceiptLine::class,
                    'source_id' => $line->id,
                    'correlation_id' => $correlationId,
                    'idempotency_key' => 'ledger-'.$line->idempotency_key,
                    'occurred_at' => $now,
                    'created_by' => $actorId,
                ]);

                GoodsReceiptLine::withoutGlobalScope('property')
                    ->where('id', $line->id)
                    ->update(['stock_movement_id' => $stockMovement->id]);

                $poLine->received_quantity = bcadd((string) $poLine->received_quantity, (string) $receiptQty, 3);
                $poLine->save();
            }

            $allLines = PurchaseOrderLine::where('purchase_order_id', $receipt->purchase_order_id)->get();
            $allFullyReceived = true;
            foreach ($allLines as $poLine) {
                if (bccomp((string) $poLine->received_quantity, (string) $poLine->ordered_quantity, 3) < 0) {
                    $allFullyReceived = false;
                    break;
                }
            }

            $poStatus = $allFullyReceived
                ? PurchaseOrderStatusEnum::FullyReceived->value
                : PurchaseOrderStatusEnum::PartiallyReceived->value;

            PurchaseOrder::withoutGlobalScope('property')
                ->where('id', $receipt->purchase_order_id)
                ->update(['status' => $poStatus]);

            $receipt->status = GoodsReceiptStatusEnum::Posted;
            $receipt->setAttribute('posted_at', $now);
            $receipt->received_at = $now;
            $receipt->save();

            return $receipt->fresh(['lines.stockMovement']);
        });
    }

    private function assertControlledReceiptAuthority(string $propertyId, string $purchaseOrderId): void
    {
        $sourceType = $this->receiptEvidence->sourceType($propertyId, $purchaseOrderId, true);

        if ($sourceType !== ReceiptSourceType::InventoryGoodsReceipt->value) {
            throw new RuntimeException('CONTROLLED_GOODS_RECEIPT_NOT_AUTHORIZED_FOR_PURCHASE_ORDER_SOURCE');
        }
    }
}
