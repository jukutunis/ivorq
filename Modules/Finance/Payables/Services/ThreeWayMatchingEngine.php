<?php

namespace Modules\Finance\Payables\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Payables\Enums\MatchExceptionEnum;
use Modules\Finance\Payables\Enums\MatchStatusEnum;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Finance\Payables\Models\ThreeWayMatch;

class ThreeWayMatchingEngine
{
    public function performMatch(SupplierInvoice $invoice): ThreeWayMatch
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = SupplierInvoice::with('lines')
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = ThreeWayMatch::with('lines')
                ->where('vendor_invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            if ($invoice->lines->isEmpty()) {
                return $this->recordMatch(
                    $invoice,
                    null,
                    null,
                    MatchStatusEnum::Exception,
                    MatchExceptionEnum::DataIntegrityError,
                    []
                );
            }

            $purchaseOrder = $this->purchaseOrder($invoice->purchase_order_id, $invoice->property_id);

            if (!$purchaseOrder) {
                return $this->recordMatch(
                    $invoice,
                    null,
                    $invoice->goods_receipt_id,
                    MatchStatusEnum::Exception,
                    MatchExceptionEnum::MissingPurchaseOrder,
                    []
                );
            }

            if ($purchaseOrder->vendor_id !== $invoice->vendor_id) {
                return $this->recordMatch(
                    $invoice,
                    $purchaseOrder->id,
                    $invoice->goods_receipt_id,
                    MatchStatusEnum::Exception,
                    MatchExceptionEnum::VendorMismatch,
                    []
                );
            }

            if (strtoupper((string) $purchaseOrder->currency_code) !== strtoupper((string) $invoice->currency_code)) {
                return $this->recordMatch(
                    $invoice,
                    $purchaseOrder->id,
                    $invoice->goods_receipt_id,
                    MatchStatusEnum::Exception,
                    MatchExceptionEnum::CurrencyMismatch,
                    []
                );
            }

            $goodsReceipt = $this->goodsReceipt($invoice->goods_receipt_id, $invoice->property_id);

            if (!$goodsReceipt) {
                return $this->recordMatch(
                    $invoice,
                    $purchaseOrder->id,
                    null,
                    MatchStatusEnum::Exception,
                    MatchExceptionEnum::MissingGoodsReceipt,
                    $this->missingReceiptEvidence($invoice, $purchaseOrder)
                );
            }

            if ($goodsReceipt->purchase_order_id !== $purchaseOrder->id || $goodsReceipt->vendor_id !== $invoice->vendor_id) {
                return $this->recordMatch(
                    $invoice,
                    $purchaseOrder->id,
                    $goodsReceipt->id,
                    MatchStatusEnum::Exception,
                    MatchExceptionEnum::ReceivingMismatch,
                    $this->missingReceiptEvidence($invoice, $purchaseOrder)
                );
            }

            $lineEvidence = [];
            $exception = null;
            $totalQtyVar = 0.0;
            $totalPriceVar = 0.0;
            $totalAmtVar = 0.0;

            foreach ($invoice->lines as $invoiceLine) {
                $purchaseOrderLine = $this->purchaseOrderLine($invoiceLine->purchase_order_line_id);

                if (!$purchaseOrderLine || $purchaseOrderLine->purchase_order_id !== $purchaseOrder->id) {
                    return $this->recordMatch(
                        $invoice,
                        $purchaseOrder->id,
                        $goodsReceipt->id,
                        MatchStatusEnum::Exception,
                        MatchExceptionEnum::InvalidLineReference,
                        $lineEvidence
                    );
                }

                if ($invoiceLine->goods_receipt_line_id === null) {
                    $lineEvidence[] = $this->lineEvidence($invoiceLine, $purchaseOrderLine, null);

                    return $this->recordMatch(
                        $invoice,
                        $purchaseOrder->id,
                        $goodsReceipt->id,
                        MatchStatusEnum::Exception,
                        MatchExceptionEnum::MissingGoodsReceipt,
                        $lineEvidence
                    );
                }

                $goodsReceiptLine = $this->goodsReceiptLine($invoiceLine->goods_receipt_line_id);

                if (!$goodsReceiptLine) {
                    $lineEvidence[] = $this->lineEvidence($invoiceLine, $purchaseOrderLine, null);

                    return $this->recordMatch(
                        $invoice,
                        $purchaseOrder->id,
                        $goodsReceipt->id,
                        MatchStatusEnum::Exception,
                        MatchExceptionEnum::MissingGoodsReceipt,
                        $lineEvidence
                    );
                }

                if ($goodsReceiptLine->receiving_document_id !== $goodsReceipt->id ||
                    $goodsReceiptLine->purchase_order_line_id !== $purchaseOrderLine->id) {
                    $lineEvidence[] = $this->lineEvidence($invoiceLine, $purchaseOrderLine, $goodsReceiptLine);

                    return $this->recordMatch(
                        $invoice,
                        $purchaseOrder->id,
                        $goodsReceipt->id,
                        MatchStatusEnum::Exception,
                        MatchExceptionEnum::ReceivingMismatch,
                        $lineEvidence
                    );
                }

                $evidence = $this->lineEvidence($invoiceLine, $purchaseOrderLine, $goodsReceiptLine);
                $lineEvidence[] = $evidence;

                $totalQtyVar += $evidence['quantity_variance'];
                $totalPriceVar += $evidence['price_variance'];
                $totalAmtVar += $evidence['amount_variance'];

                if ($exception === null && abs($evidence['quantity_variance']) > 0.0001) {
                    $exception = MatchExceptionEnum::QuantityVariance;
                }

                if ($exception === null && abs($evidence['price_variance']) > 0.0001) {
                    $exception = MatchExceptionEnum::PriceVariance;
                }

                if ($exception === null && abs($evidence['amount_variance']) > 0.0001) {
                    $exception = MatchExceptionEnum::LineAmountVariance;
                }
            }

            return $this->recordMatch(
                $invoice,
                $purchaseOrder->id,
                $goodsReceipt->id,
                $exception === null ? MatchStatusEnum::Matched : MatchStatusEnum::Exception,
                $exception,
                $lineEvidence,
                round($totalQtyVar, 4),
                round($totalPriceVar, 2),
                round($totalAmtVar, 2)
            );
        });
    }

    private function recordMatch(
        SupplierInvoice $invoice,
        ?string $purchaseOrderId,
        ?string $goodsReceiptId,
        MatchStatusEnum $status,
        ?MatchExceptionEnum $exception,
        array $lineEvidence,
        float $totalQuantityVariance = 0.0,
        float $totalPriceVariance = 0.0,
        float $totalAmountVariance = 0.0,
    ): ThreeWayMatch
    {
        $match = ThreeWayMatch::create([
            'property_id' => $invoice->property_id,
            'vendor_invoice_id' => $invoice->id,
            'purchase_order_id' => $purchaseOrderId,
            'goods_receipt_id' => $goodsReceiptId,
            'status' => $status,
            'exception_code' => $exception,
            'total_quantity_variance' => $totalQuantityVariance,
            'total_price_variance' => $totalPriceVariance,
            'total_amount_variance' => $totalAmountVariance,
            'created_by' => $invoice->created_by,
            'updated_by' => $invoice->created_by,
        ]);

        foreach ($lineEvidence as $line) {
            $match->lines()->create($line + [
                'created_by' => $invoice->created_by,
                'updated_by' => $invoice->created_by,
            ]);
        }

        return $match->fresh(['lines']);
    }

    private function missingReceiptEvidence(SupplierInvoice $invoice, object $purchaseOrder): array
    {
        $evidence = [];

        foreach ($invoice->lines as $invoiceLine) {
            $purchaseOrderLine = $this->purchaseOrderLine($invoiceLine->purchase_order_line_id);

            if ($purchaseOrderLine && $purchaseOrderLine->purchase_order_id === $purchaseOrder->id) {
                $evidence[] = $this->lineEvidence($invoiceLine, $purchaseOrderLine, null);
            }
        }

        return $evidence;
    }

    private function lineEvidence(object $invoiceLine, object $purchaseOrderLine, ?object $goodsReceiptLine): array
    {
        $poQuantity = (float) $purchaseOrderLine->ordered_quantity;
        $poPrice = (float) $purchaseOrderLine->unit_cost;
        $receiptQuantity = $goodsReceiptLine ? (float) $goodsReceiptLine->received_quantity : 0.0;
        $invoiceQuantity = (float) $invoiceLine->quantity;
        $invoicePrice = (float) $invoiceLine->unit_price;
        $invoiceAmount = (float) $invoiceLine->line_total;
        $expectedAmount = $receiptQuantity * $poPrice;

        return [
            'vendor_invoice_line_id' => $invoiceLine->id,
            'purchase_order_line_id' => $purchaseOrderLine->id,
            'goods_receipt_line_id' => $goodsReceiptLine?->id,
            'inventory_item_id' => $goodsReceiptLine?->inventory_item_id ?? $invoiceLine->inventory_item_id,
            'po_quantity' => $poQuantity,
            'po_price' => $poPrice,
            'grn_quantity' => $receiptQuantity,
            'invoice_quantity' => $invoiceQuantity,
            'invoice_price' => $invoicePrice,
            'quantity_variance' => round($invoiceQuantity - $receiptQuantity, 4),
            'price_variance' => round($invoicePrice - $poPrice, 2),
            'amount_variance' => round($invoiceAmount - $expectedAmount, 2),
        ];
    }

    private function purchaseOrder(?string $id, string $propertyId): ?object
    {
        if ($id === null) {
            return null;
        }

        return DB::table('purchase_orders')
            ->where('id', $id)
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function purchaseOrderLine(?string $id): ?object
    {
        if ($id === null) {
            return null;
        }

        return DB::table('purchase_order_lines')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();
    }

    private function goodsReceipt(?string $id, string $propertyId): ?object
    {
        if ($id === null) {
            return null;
        }

        return DB::table('receiving_documents')
            ->where('id', $id)
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function goodsReceiptLine(?string $id): ?object
    {
        if ($id === null) {
            return null;
        }

        return DB::table('receiving_lines')
            ->where('id', $id)
            ->first();
    }
}
