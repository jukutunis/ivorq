<?php

namespace Modules\Finance\Payables\Services;

use DomainException;
use Illuminate\Support\Facades\DB;

class SupplierInvoiceIntakeSourceService
{
    private const ELIGIBLE_PURCHASE_ORDER_STATUSES = [
        'APPROVED',
        'ISSUED',
        'PARTIALLY_RECEIVED',
        'FULLY_RECEIVED',
    ];

    private const ELIGIBLE_RECEIVING_STATUSES = ['submitted', 'approved'];

    public function project(string $propertyId): array
    {
        return DB::table('purchase_orders as po')
            ->join('vendors as v', 'v.id', '=', 'po.vendor_id')
            ->where('po.property_id', $propertyId)
            ->whereIn('po.status', self::ELIGIBLE_PURCHASE_ORDER_STATUSES)
            ->whereNull('po.deleted_at')
            ->whereNull('v.deleted_at')
            ->where('v.is_active', true)
            ->where('v.is_approved', true)
            ->orderByDesc('po.created_at')
            ->limit(100)
            ->get([
                'po.id', 'po.po_no', 'po.vendor_id', 'v.name as vendor_name',
                'po.currency_code', 'po.subtotal', 'po.tax_amount', 'po.total_amount', 'po.status',
            ])
            ->map(function (object $purchaseOrder): array {
                $receipts = DB::table('receiving_documents')
                    ->where('purchase_order_id', $purchaseOrder->id)
                    ->whereIn('status', self::ELIGIBLE_RECEIVING_STATUSES)
                    ->whereNull('deleted_at')
                    ->orderByDesc('received_at')
                    ->get(['id', 'grn_number', 'status', 'received_at'])
                    ->map(fn (object $receipt): array => (array) $receipt)
                    ->values()
                    ->all();

                return [
                    'id' => $purchaseOrder->id,
                    'po_no' => $purchaseOrder->po_no,
                    'vendor_id' => $purchaseOrder->vendor_id,
                    'vendor_name' => $purchaseOrder->vendor_name,
                    'currency_code' => $purchaseOrder->currency_code,
                    'subtotal' => (string) $purchaseOrder->subtotal,
                    'tax_amount' => (string) $purchaseOrder->tax_amount,
                    'total_amount' => (string) $purchaseOrder->total_amount,
                    'status' => $purchaseOrder->status,
                    'receipts' => $receipts,
                ];
            })
            ->values()
            ->all();
    }

    public function resolve(string $propertyId, array $input): array
    {
        $purchaseOrderId = trim((string) ($input['purchase_order_id'] ?? ''));
        $receiptId = $this->nullableString($input['goods_receipt_id'] ?? null);

        if ($purchaseOrderId === '') {
            throw new DomainException('A purchase order source is required.');
        }

        $purchaseOrder = DB::table('purchase_orders')
            ->where('id', $purchaseOrderId)
            ->where('property_id', $propertyId)
            ->whereIn('status', self::ELIGIBLE_PURCHASE_ORDER_STATUSES)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();

        if (! $purchaseOrder) {
            throw new DomainException('Purchase order source is unavailable in the active property or lifecycle.');
        }

        $vendor = DB::table('vendors')
            ->where('id', $purchaseOrder->vendor_id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('is_approved', true)
            ->lockForUpdate()
            ->first();

        if (! $vendor || ($vendor->property_id !== null && $vendor->property_id !== $propertyId)) {
            throw new DomainException('Purchase order supplier is not active and approved for the property.');
        }

        if (round((float) $purchaseOrder->tax_amount, 2) !== 0.0) {
            throw new DomainException('Tax-bearing purchase orders are not supported by this intake path.');
        }

        $receipt = null;
        if ($receiptId !== null) {
            $receipt = DB::table('receiving_documents')
                ->where('id', $receiptId)
                ->where('property_id', $propertyId)
                ->where('purchase_order_id', $purchaseOrder->id)
                ->where('vendor_id', $purchaseOrder->vendor_id)
                ->whereIn('status', self::ELIGIBLE_RECEIVING_STATUSES)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if (! $receipt) {
                throw new DomainException('Receiving evidence does not belong to the selected purchase order and property.');
            }
        }

        $lines = $receipt
            ? $this->receiptLines($receipt->id, $purchaseOrder->id)
            : $this->purchaseOrderLines($purchaseOrder->id);

        if ($lines === []) {
            throw new DomainException('The selected source contains no invoiceable lines.');
        }

        return [
            'property_id' => $propertyId,
            'vendor_id' => $purchaseOrder->vendor_id,
            'purchase_order_id' => $purchaseOrder->id,
            'goods_receipt_id' => $receipt?->id,
            'invoice_number' => $input['invoice_number'] ?? null,
            'invoice_date' => $input['invoice_date'] ?? null,
            'due_date' => $input['due_date'] ?? null,
            'currency_code' => $purchaseOrder->currency_code,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'remarks' => $input['remarks'] ?? null,
            'registration_idempotency_key' => $input['registration_idempotency_key'] ?? null,
            'lines' => $lines,
        ];
    }

    private function receiptLines(string $receiptId, string $purchaseOrderId): array
    {
        return DB::table('receiving_lines as rl')
            ->join('purchase_order_lines as pol', 'pol.id', '=', 'rl.purchase_order_line_id')
            ->where('rl.receiving_document_id', $receiptId)
            ->where('pol.purchase_order_id', $purchaseOrderId)
            ->whereNull('pol.deleted_at')
            ->orderBy('rl.id')
            ->get([
                'pol.id as purchase_order_line_id', 'rl.id as goods_receipt_line_id',
                'pol.inventory_item_id', 'pol.description', 'rl.received_quantity as quantity',
                'pol.unit_cost',
            ])
            ->map(fn (object $line): array => [
                'purchase_order_line_id' => $line->purchase_order_line_id,
                'goods_receipt_line_id' => $line->goods_receipt_line_id,
                'inventory_item_id' => $line->inventory_item_id,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_cost,
            ])
            ->values()
            ->all();
    }

    private function purchaseOrderLines(string $purchaseOrderId): array
    {
        return DB::table('purchase_order_lines')
            ->where('purchase_order_id', $purchaseOrderId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'inventory_item_id', 'description', 'ordered_quantity', 'unit_cost'])
            ->map(fn (object $line): array => [
                'purchase_order_line_id' => $line->id,
                'goods_receipt_line_id' => null,
                'inventory_item_id' => $line->inventory_item_id,
                'description' => $line->description,
                'quantity' => (string) $line->ordered_quantity,
                'unit_price' => (string) $line->unit_cost,
            ])
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
