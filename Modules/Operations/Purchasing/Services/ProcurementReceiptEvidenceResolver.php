<?php

namespace Modules\Operations\Purchasing\Services;

use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Operations\Purchasing\Contracts\ProcurementReceiptEvidenceProvider;
use Modules\Operations\Purchasing\Enums\ReceiptSourceType;

class ProcurementReceiptEvidenceResolver implements ProcurementReceiptEvidenceProvider
{
    private const ELIGIBLE_LEGACY_STATUSES = ['submitted', 'approved'];

    public function sourceType(string $propertyId, string $purchaseOrderId, bool $lockForUpdate = false): string
    {
        $query = DB::table('purchase_order_receipt_source_authorities')
            ->where('property_id', $propertyId)
            ->where('purchase_order_id', $purchaseOrderId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $authority = $query->first(['source_type']);

        if (! $authority) {
            throw new DomainException('Purchase Order receipt source authority is unavailable.');
        }

        $sourceType = ReceiptSourceType::tryFrom((string) $authority->source_type);

        if (! $sourceType) {
            throw new DomainException('Purchase Order receipt source authority is invalid.');
        }

        $hasControlled = DB::table('goods_receipts')
            ->where('property_id', $propertyId)
            ->where('purchase_order_id', $purchaseOrderId)
            ->exists();
        $hasLegacy = DB::table('receiving_documents')
            ->where('property_id', $propertyId)
            ->where('purchase_order_id', $purchaseOrderId)
            ->exists();

        if (($hasControlled && $hasLegacy)
            || ($hasControlled && $sourceType !== ReceiptSourceType::InventoryGoodsReceipt)
            || ($hasLegacy && $sourceType !== ReceiptSourceType::LegacyReceivingDocument)) {
            throw new DomainException('RECEIPT_SOURCE_CONFLICT_REQUIRES_MANUAL_RESOLUTION');
        }

        return $sourceType->value;
    }

    public function receipts(string $propertyId, string $purchaseOrderId): array
    {
        $sourceType = ReceiptSourceType::from($this->sourceType($propertyId, $purchaseOrderId));

        $query = $sourceType === ReceiptSourceType::InventoryGoodsReceipt
            ? $this->controlledReceiptQuery($propertyId, $purchaseOrderId)
            : $this->legacyReceiptQuery($propertyId, $purchaseOrderId);

        return $query
            ->orderByDesc($sourceType === ReceiptSourceType::InventoryGoodsReceipt ? 'gr.posted_at' : 'rd.received_at')
            ->get($this->receiptColumns($sourceType))
            ->map(fn (object $receipt): array => $this->normalizeReceipt($receipt, $sourceType))
            ->all();
    }

    public function receipt(
        string $propertyId,
        string $purchaseOrderId,
        string $receiptId,
        bool $lockForUpdate = false,
    ): ?array {
        $sourceType = ReceiptSourceType::from(
            $this->sourceType($propertyId, $purchaseOrderId, $lockForUpdate)
        );
        $query = $sourceType === ReceiptSourceType::InventoryGoodsReceipt
            ? $this->controlledReceiptQuery($propertyId, $purchaseOrderId)
            : $this->legacyReceiptQuery($propertyId, $purchaseOrderId);

        $query->where(
            $sourceType === ReceiptSourceType::InventoryGoodsReceipt ? 'gr.id' : 'rd.id',
            $receiptId
        );

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $receipt = $query->first($this->receiptColumns($sourceType));

        return $receipt ? $this->normalizeReceipt($receipt, $sourceType) : null;
    }

    public function lines(
        string $propertyId,
        string $purchaseOrderId,
        string $receiptId,
        bool $lockForUpdate = false,
        array $lineIds = [],
    ): array {
        $receipt = $this->receipt($propertyId, $purchaseOrderId, $receiptId, $lockForUpdate);

        if (! $receipt) {
            return [];
        }

        $sourceType = ReceiptSourceType::from($receipt['source_type']);
        $query = $sourceType === ReceiptSourceType::InventoryGoodsReceipt
            ? $this->controlledLineQuery($propertyId, $purchaseOrderId, $receiptId)
            : $this->legacyLineQuery($propertyId, $purchaseOrderId, $receiptId);

        $lineIds = array_values(array_unique(array_map('strval', $lineIds)));
        sort($lineIds);

        if ($lineIds !== []) {
            $query->whereIn(
                $sourceType === ReceiptSourceType::InventoryGoodsReceipt ? 'grl.id' : 'rl.id',
                $lineIds
            );
        }

        $query->orderBy($sourceType === ReceiptSourceType::InventoryGoodsReceipt ? 'grl.id' : 'rl.id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $lines = $query->get($this->lineColumns($sourceType))
            ->map(fn (object $line): array => $this->normalizeLine($line, $sourceType))
            ->all();

        if ($lockForUpdate && $lineIds !== [] && count($lines) !== count($lineIds)) {
            throw new DomainException('Supplier invoice receipt-line evidence is unavailable or ineligible.');
        }

        foreach ($lines as $line) {
            if ($line['receipt_id'] !== $receiptId
                || $line['property_id'] !== $propertyId
                || $line['purchase_order_id'] !== $purchaseOrderId
                || $line['inventory_item_id'] !== $line['purchase_order_inventory_item_id']
                || $line['inventory_unit_id'] !== $line['purchase_order_unit_id']
                || (float) $line['received_quantity'] <= 0) {
                throw new DomainException('Supplier invoice receipt-line provenance is inconsistent.');
            }

            if ($sourceType === ReceiptSourceType::InventoryGoodsReceipt
                && ($line['commercial_evidence_hash'] === null
                    || $line['evidence_inventory_item_id'] !== $line['inventory_item_id']
                    || $line['evidence_inventory_unit_id'] !== $line['inventory_unit_id'])) {
                throw new DomainException('Controlled Goods Receipt immutable commercial evidence is unavailable or inconsistent.');
            }
        }

        return $lines;
    }

    private function controlledReceiptQuery(string $propertyId, string $purchaseOrderId): Builder
    {
        return DB::table('goods_receipts as gr')
            ->join('purchase_orders as po', 'po.id', '=', 'gr.purchase_order_id')
            ->where('gr.property_id', $propertyId)
            ->where('gr.purchase_order_id', $purchaseOrderId)
            ->where('gr.status', 'POSTED')
            ->where('po.property_id', $propertyId)
            ->whereNull('po.deleted_at');
    }

    private function legacyReceiptQuery(string $propertyId, string $purchaseOrderId): Builder
    {
        return DB::table('receiving_documents as rd')
            ->join('purchase_orders as po', 'po.id', '=', 'rd.purchase_order_id')
            ->where('rd.property_id', $propertyId)
            ->where('rd.purchase_order_id', $purchaseOrderId)
            ->whereColumn('rd.vendor_id', 'po.vendor_id')
            ->whereIn('rd.status', self::ELIGIBLE_LEGACY_STATUSES)
            ->whereNull('rd.deleted_at')
            ->whereNull('po.deleted_at');
    }

    private function controlledLineQuery(string $propertyId, string $purchaseOrderId, string $receiptId): Builder
    {
        return DB::table('goods_receipt_lines as grl')
            ->join('goods_receipts as gr', 'gr.id', '=', 'grl.goods_receipt_id')
            ->join('purchase_order_lines as pol', 'pol.id', '=', 'grl.purchase_order_line_id')
            ->join('goods_receipt_line_commercial_evidences as ce', 'ce.goods_receipt_line_id', '=', 'grl.id')
            ->where('grl.property_id', $propertyId)
            ->where('grl.goods_receipt_id', $receiptId)
            ->where('gr.purchase_order_id', $purchaseOrderId)
            ->where('gr.status', 'POSTED')
            ->where('pol.purchase_order_id', $purchaseOrderId)
            ->whereNull('pol.deleted_at');
    }

    private function legacyLineQuery(string $propertyId, string $purchaseOrderId, string $receiptId): Builder
    {
        return DB::table('receiving_lines as rl')
            ->join('receiving_documents as rd', 'rd.id', '=', 'rl.receiving_document_id')
            ->join('purchase_order_lines as pol', 'pol.id', '=', 'rl.purchase_order_line_id')
            ->where('rd.property_id', $propertyId)
            ->where('rl.receiving_document_id', $receiptId)
            ->where('rd.purchase_order_id', $purchaseOrderId)
            ->whereIn('rd.status', self::ELIGIBLE_LEGACY_STATUSES)
            ->whereNull('rd.deleted_at')
            ->where('pol.purchase_order_id', $purchaseOrderId)
            ->whereNull('pol.deleted_at');
    }

    private function receiptColumns(ReceiptSourceType $sourceType): array
    {
        if ($sourceType === ReceiptSourceType::InventoryGoodsReceipt) {
            return [
                'gr.id', 'gr.property_id', 'gr.purchase_order_id', 'po.vendor_id',
                'gr.receipt_number as receipt_number', 'gr.status', 'gr.received_at', 'gr.posted_at',
            ];
        }

        return [
            'rd.id', 'rd.property_id', 'rd.purchase_order_id', 'rd.vendor_id',
            'rd.grn_number as receipt_number', 'rd.status', 'rd.received_at',
            DB::raw('NULL as posted_at'),
        ];
    }

    private function lineColumns(ReceiptSourceType $sourceType): array
    {
        if ($sourceType === ReceiptSourceType::InventoryGoodsReceipt) {
            return [
                'grl.id', 'grl.goods_receipt_id as receipt_id', 'grl.property_id',
                'gr.purchase_order_id', 'grl.purchase_order_line_id', 'grl.inventory_item_id',
                'grl.inventory_unit_id', 'grl.received_quantity', 'pol.inventory_item_id as purchase_order_inventory_item_id',
                'pol.unit_id as purchase_order_unit_id', 'pol.description',
                'ce.purchase_order_unit_cost_snapshot as unit_cost', 'ce.commercial_evidence_hash',
                'ce.inventory_item_id as evidence_inventory_item_id', 'ce.inventory_unit_id as evidence_inventory_unit_id',
            ];
        }

        return [
            'rl.id', 'rl.receiving_document_id as receipt_id', 'rd.property_id',
            'rd.purchase_order_id', 'rl.purchase_order_line_id', 'rl.inventory_item_id',
            'rl.inventory_unit_id', 'rl.received_quantity', 'pol.inventory_item_id as purchase_order_inventory_item_id',
            'pol.unit_id as purchase_order_unit_id', 'pol.description', 'rl.unit_cost',
            DB::raw('NULL as commercial_evidence_hash'),
            DB::raw('NULL as evidence_inventory_item_id'),
            DB::raw('NULL as evidence_inventory_unit_id'),
        ];
    }

    private function normalizeReceipt(object $receipt, ReceiptSourceType $sourceType): array
    {
        return [
            'source_type' => $sourceType->value,
            'source_label' => $sourceType->label(),
            'id' => (string) $receipt->id,
            'property_id' => (string) $receipt->property_id,
            'purchase_order_id' => (string) $receipt->purchase_order_id,
            'vendor_id' => (string) $receipt->vendor_id,
            'receipt_number' => (string) $receipt->receipt_number,
            'status' => (string) $receipt->status,
            'received_at' => $receipt->received_at,
            'posted_at' => $receipt->posted_at,
        ];
    }

    private function normalizeLine(object $line, ReceiptSourceType $sourceType): array
    {
        return [
            'source_type' => $sourceType->value,
            'id' => (string) $line->id,
            'receipt_id' => (string) $line->receipt_id,
            'property_id' => (string) $line->property_id,
            'purchase_order_id' => (string) $line->purchase_order_id,
            'purchase_order_line_id' => (string) $line->purchase_order_line_id,
            'inventory_item_id' => (string) $line->inventory_item_id,
            'inventory_unit_id' => (string) $line->inventory_unit_id,
            'purchase_order_inventory_item_id' => (string) $line->purchase_order_inventory_item_id,
            'purchase_order_unit_id' => (string) $line->purchase_order_unit_id,
            'description' => (string) $line->description,
            'received_quantity' => (string) $line->received_quantity,
            'unit_cost' => (string) $line->unit_cost,
            'commercial_evidence_hash' => $line->commercial_evidence_hash,
            'evidence_inventory_item_id' => $line->evidence_inventory_item_id,
            'evidence_inventory_unit_id' => $line->evidence_inventory_unit_id,
        ];
    }
}
