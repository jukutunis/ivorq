<?php

namespace Modules\Finance\Payables\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Operations\Purchasing\Contracts\ProcurementReceiptEvidenceProvider;

class SupplierInvoiceIntakeSourceService
{
    private const ELIGIBLE_PURCHASE_ORDER_STATUSES = [
        'APPROVED', 'ISSUED', 'PARTIALLY_RECEIVED', 'FULLY_RECEIVED',
    ];

    public function __construct(
        private readonly ProcurementReceiptEvidenceProvider $receiptEvidence,
    ) {}

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
            ->map(function (object $purchaseOrder) use ($propertyId): array {
                $receipts = collect($this->receiptEvidence->receipts($propertyId, $purchaseOrder->id))
                    ->map(function (array $receipt) use ($propertyId, $purchaseOrder): ?array {
                        $invoiceableLines = $this->receiptLines(
                            $propertyId,
                            $purchaseOrder->id,
                            $receipt['id'],
                            $receipt['source_type']
                        );

                        if ($invoiceableLines === []) {
                            return null;
                        }

                        return $receipt + [
                            'grn_number' => $receipt['receipt_number'],
                            'remaining_quantity' => number_format(
                                array_sum(array_map(
                                    fn (array $line): float => (float) $line['quantity'],
                                    $invoiceableLines
                                )),
                                3,
                                '.',
                                ''
                            ),
                        ];
                    })
                    ->filter()
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
                    'receipt_source_type' => $this->receiptEvidence->sourceType($propertyId, $purchaseOrder->id),
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
        $requestedSourceType = $this->nullableString($input['receipt_source_type'] ?? null);

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

        $propertyCompanyId = DB::table('properties')->where('id', $propertyId)->value('company_id');
        $vendor = DB::table('vendors')
            ->where('id', $purchaseOrder->vendor_id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('is_approved', true)
            ->lockForUpdate()
            ->first();

        if (! $vendor || $propertyCompanyId === null || $vendor->company_id !== $propertyCompanyId) {
            throw new DomainException('Purchase order supplier is not active and approved for the property.');
        }

        if (round((float) $purchaseOrder->tax_amount, 2) !== 0.0) {
            throw new DomainException('Tax-bearing purchase orders are not supported by this intake path.');
        }

        $sourceType = null;
        $receipt = null;
        if ($receiptId !== null) {
            $sourceType = $this->receiptEvidence->sourceType($propertyId, $purchaseOrder->id, true);

            if ($requestedSourceType !== null && $requestedSourceType !== $sourceType) {
                throw new DomainException('Supplier invoice receipt source type conflicts with Purchase Order authority.');
            }

            $receipt = $this->receiptEvidence->receipt(
                $propertyId,
                $purchaseOrder->id,
                $receiptId,
                true
            );

            if (! $receipt || $receipt['vendor_id'] !== $purchaseOrder->vendor_id) {
                throw new DomainException('Receiving evidence does not belong to the selected purchase order and property.');
            }
        } elseif ($requestedSourceType !== null) {
            throw new DomainException('Receipt source type cannot be supplied without receipt evidence.');
        }

        $lines = $receipt
            ? $this->receiptLines($propertyId, $purchaseOrder->id, $receipt['id'], $sourceType, true)
            : $this->purchaseOrderLines($purchaseOrder->id);

        if ($lines === []) {
            throw new DomainException('The selected source contains no invoiceable lines.');
        }

        return [
            'property_id' => $propertyId,
            'vendor_id' => $purchaseOrder->vendor_id,
            'purchase_order_id' => $purchaseOrder->id,
            'goods_receipt_id' => $receipt['id'] ?? null,
            'receipt_source_type' => $sourceType,
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

    public function assertReceiptCapacity(array $data): void
    {
        $sourceType = $data['receipt_source_type'];
        $requested = [];

        foreach ($data['lines'] as $line) {
            $receiptLineId = $line['goods_receipt_line_id'];

            if ($receiptLineId === null) {
                continue;
            }

            if ($sourceType === null) {
                throw new DomainException('Receipt source type is required for receipt-backed invoice lines.');
            }

            if (isset($requested[$receiptLineId])
                && $requested[$receiptLineId]['purchase_order_line_id'] !== $line['purchase_order_line_id']) {
                throw new DomainException('Supplier invoice receipt-line provenance is inconsistent.');
            }

            $requested[$receiptLineId] ??= [
                'purchase_order_line_id' => $line['purchase_order_line_id'],
                'quantity' => 0.0,
            ];
            $requested[$receiptLineId]['quantity'] += (float) $line['quantity'];
        }

        if ($requested === []) {
            return;
        }

        $receiptLineIds = array_keys($requested);
        sort($receiptLineIds);
        $receiptLines = $this->receiptEvidence->lines(
            $data['property_id'],
            $data['purchase_order_id'],
            $data['goods_receipt_id'],
            true,
            $receiptLineIds
        );
        $claimed = $this->claimedQuantities($sourceType, $receiptLineIds);

        foreach ($receiptLines as $receiptLine) {
            $lineRequest = $requested[$receiptLine['id']];

            if ($receiptLine['source_type'] !== $sourceType
                || $receiptLine['receipt_id'] !== $data['goods_receipt_id']
                || $receiptLine['purchase_order_line_id'] !== $lineRequest['purchase_order_line_id']) {
                throw new DomainException('Supplier invoice receiving capacity conflicts with source provenance.');
            }

            $remaining = max(
                0.0,
                round((float) $receiptLine['received_quantity'] - ($claimed[$receiptLine['id']] ?? 0.0), 3)
            );

            if (round($lineRequest['quantity'], 3) - $remaining > 0.0005) {
                throw new DomainException('Supplier invoice quantity exceeds remaining receipt capacity.');
            }
        }
    }

    private function receiptLines(
        string $propertyId,
        string $purchaseOrderId,
        string $receiptId,
        string $sourceType,
        bool $lockForUpdate = false,
    ): array {
        $lines = $this->receiptEvidence->lines(
            $propertyId,
            $purchaseOrderId,
            $receiptId,
            $lockForUpdate
        );
        $claimed = $this->claimedQuantities($sourceType, array_column($lines, 'id'));

        return collect($lines)
            ->map(function (array $line) use ($claimed): ?array {
                $remaining = max(
                    0.0,
                    round((float) $line['received_quantity'] - ($claimed[$line['id']] ?? 0.0), 3)
                );

                if ($remaining <= 0.0) {
                    return null;
                }

                return [
                    'purchase_order_line_id' => $line['purchase_order_line_id'],
                    'goods_receipt_line_id' => $line['id'],
                    'receipt_commercial_evidence_hash' => $line['commercial_evidence_hash'],
                    'inventory_item_id' => $line['inventory_item_id'],
                    'description' => $line['description'],
                    'quantity' => number_format($remaining, 3, '.', ''),
                    'unit_price' => $line['unit_cost'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function claimedQuantities(string $sourceType, array $receiptLineIds): array
    {
        if ($receiptLineIds === []) {
            return [];
        }

        return DB::table('vendor_invoice_lines as vil')
            ->join('vendor_invoices as vi', 'vi.id', '=', 'vil.vendor_invoice_id')
            ->where('vi.receipt_source_type', $sourceType)
            ->whereIn('vil.goods_receipt_line_id', $receiptLineIds)
            ->whereIn('vi.status', [SupplierInvoice::STATUS_REGISTERED, SupplierInvoice::STATUS_APPROVED])
            ->whereNull('vi.deleted_at')
            ->groupBy('vil.goods_receipt_line_id')
            ->get([
                'vil.goods_receipt_line_id',
                DB::raw('SUM(vil.quantity) as claimed_quantity'),
            ])
            ->mapWithKeys(fn (object $line): array => [
                $line->goods_receipt_line_id => round((float) $line->claimed_quantity, 3),
            ])
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
                'receipt_commercial_evidence_hash' => null,
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
