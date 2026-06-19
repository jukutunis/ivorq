<?php

namespace Modules\Finance\Payables\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Payables\Enums\MatchExceptionEnum;
use Modules\Finance\Payables\Enums\MatchStatusEnum;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceStatusEnum;
use Modules\Finance\Payables\Models\ThreeWayMatch;
use Modules\Finance\AccountsPayable\Models\ApInvoice;

class ThreeWayMatchingEngine
{
    public function performMatch(ApInvoice $invoice): ThreeWayMatch
    {
        return DB::transaction(function () use ($invoice) {
            if ($invoice->lines->isEmpty()) {
                return $this->createExceptionMatch($invoice, null, null, MatchExceptionEnum::DataIntegrityError);
            }

            $firstLine = $invoice->lines->first();
            $receiptLine = $firstLine->receiptLine;
            $poLine = $receiptLine?->purchaseOrderLine;

            $poId = $poLine?->purchase_order_id;
            $grnId = $receiptLine?->receiving_document_id;

            if (!$poId) {
                return $this->createExceptionMatch($invoice, $poId, $grnId, MatchExceptionEnum::MissingPurchaseOrder);
            }

            if (!$grnId) {
                return $this->createExceptionMatch($invoice, $poId, $grnId, MatchExceptionEnum::MissingGoodsReceipt);
            }

            $match = ThreeWayMatch::create([
                'property_id' => $invoice->property_id,
                'vendor_invoice_id' => $invoice->id,
                'purchase_order_id' => $poId,
                'goods_receipt_id' => $grnId,
                'status' => MatchStatusEnum::Matched,
            ]);

            $totalQtyVar = 0;
            $totalPriceVar = 0;
            $totalAmtVar = 0;
            $hasVariance = false;

            foreach ($invoice->lines as $invoiceLine) {
                $grnLine = $invoiceLine->receiptLine;
                $poLine = $grnLine?->purchaseOrderLine;

                if (!$poLine || !$grnLine) {
                    DB::rollBack();
                    return $this->createExceptionMatch($invoice, $poId, $grnId, MatchExceptionEnum::DataIntegrityError);
                }

                if ($poLine->purchaseOrder->property_id !== $invoice->property_id ||
                    $grnLine->receivingDocument->property_id !== $invoice->property_id) {
                    DB::rollBack();
                    return $this->createExceptionMatch($invoice, $poId, $grnId, MatchExceptionEnum::DataIntegrityError);
                }

                $poQty = $poLine->ordered_quantity;
                $poPrice = $poLine->unit_cost;

                $grnQty = $grnLine->received_quantity;

                $invQty = $invoiceLine->quantity;
                $invPrice = $invoiceLine->unit_price;

                $qtyVar = $invQty - $grnQty;
                $priceVar = $invPrice - $poPrice;

                $billedAmt = $invQty * $invPrice;
                $expectedAmt = $grnQty * $poPrice;
                $amtVar = $billedAmt - $expectedAmt;

                $totalQtyVar += $qtyVar;
                $totalPriceVar += $priceVar;
                $totalAmtVar += $amtVar;

                if (abs($qtyVar) > 0 || abs($priceVar) > 0 || abs($amtVar) > 0) {
                    $hasVariance = true;
                }

                $match->lines()->create([
                    'vendor_invoice_line_id' => $invoiceLine->id,
                    'purchase_order_line_id' => $poLine->id,
                    'goods_receipt_line_id' => $grnLine->id,
                    'inventory_item_id' => $grnLine->inventory_item_id,
                    'po_quantity' => $poQty,
                    'po_price' => $poPrice,
                    'grn_quantity' => $grnQty,
                    'invoice_quantity' => $invQty,
                    'invoice_price' => $invPrice,
                    'quantity_variance' => $qtyVar,
                    'price_variance' => $priceVar,
                    'amount_variance' => $amtVar,
                ]);
            }

            $finalStatus = $hasVariance ? MatchStatusEnum::MatchedWithVariance : MatchStatusEnum::Matched;

            $match->update([
                'status' => $finalStatus,
                'total_quantity_variance' => $totalQtyVar,
                'total_price_variance' => $totalPriceVar,
                'total_amount_variance' => $totalAmtVar,
            ]);

            $invoice->update(['status' => ApInvoiceStatusEnum::APPROVED]);

            return $match;
        });
    }

    private function createExceptionMatch(ApInvoice $invoice, ?string $poId, ?string $grnId, MatchExceptionEnum $exception): ThreeWayMatch
    {
        return DB::transaction(function () use ($invoice, $poId, $grnId, $exception) {
            return ThreeWayMatch::create([
                'property_id' => $invoice->property_id,
                'vendor_invoice_id' => $invoice->id,
                'purchase_order_id' => $poId,
                'goods_receipt_id' => $grnId,
                'status' => MatchStatusEnum::Exception,
                'exception_code' => $exception,
            ]);
        });
    }
}
