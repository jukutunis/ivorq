<?php

namespace Modules\Finance\Payables\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Payables\Enums\MatchExceptionEnum;
use Modules\Finance\Payables\Enums\MatchStatusEnum;
use Modules\Finance\Payables\Enums\VendorInvoiceStatusEnum;
use Modules\Finance\Payables\Models\ThreeWayMatch;
use Modules\Finance\Payables\Models\VendorInvoice;
use Modules\Operations\Purchasing\Models\GoodsReceiptLine;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;

class ThreeWayMatchingEngine
{
    public function performMatch(VendorInvoice $invoice): ThreeWayMatch
    {
        return DB::transaction(function () use ($invoice) {
            // BR-001: Validate PO and GRN availability
            if (!$invoice->purchase_order_id) {
                return $this->createExceptionMatch($invoice, MatchExceptionEnum::MissingPurchaseOrder);
            }

            if (!$invoice->goods_receipt_id) {
                return $this->createExceptionMatch($invoice, MatchExceptionEnum::MissingGoodsReceipt);
            }

            // Create base match record
            $match = ThreeWayMatch::create([
                'property_id' => $invoice->property_id,
                'vendor_invoice_id' => $invoice->id,
                'purchase_order_id' => $invoice->purchase_order_id,
                'goods_receipt_id' => $invoice->goods_receipt_id,
                'status' => MatchStatusEnum::Matched, // Temporary
            ]);

            $totalQtyVar = 0;
            $totalPriceVar = 0;
            $totalAmtVar = 0;
            $hasVariance = false;

            foreach ($invoice->lines as $invoiceLine) {
                if (!$invoiceLine->purchase_order_line_id || !$invoiceLine->goods_receipt_line_id) {
                    // Fail the whole matching process and roll back if any line is invalid
                    DB::rollBack();
                    return $this->createExceptionMatch($invoice, MatchExceptionEnum::InvalidLineReference);
                }

                $poLine = PurchaseOrderLine::find($invoiceLine->purchase_order_line_id);
                $grnLine = GoodsReceiptLine::find($invoiceLine->goods_receipt_line_id);

                if (!$poLine || !$grnLine) {
                    DB::rollBack();
                    return $this->createExceptionMatch($invoice, MatchExceptionEnum::DataIntegrityError);
                }

                // Property Isolation Check
                if ($poLine->purchaseOrder->property_id !== $invoice->property_id ||
                    $grnLine->goodsReceipt->property_id !== $invoice->property_id) {
                    DB::rollBack();
                    return $this->createExceptionMatch($invoice, MatchExceptionEnum::DataIntegrityError);
                }

                $poQty = $poLine->quantity_ordered;
                $poPrice = $poLine->unit_cost;

                $grnQty = $grnLine->quantity_received;

                $invQty = $invoiceLine->quantity;
                $invPrice = $invoiceLine->unit_price;

                // BR-002: Quantity Variance = Invoice - GRN
                $qtyVar = $invQty - $grnQty;

                // BR-003: Price Variance = Invoice - PO
                $priceVar = $invPrice - $poPrice;

                // BR-004: Amount Variance
                // The expected liability amount for this line should be (GRN Quantity * PO Price)
                // The billed amount is (Invoice Quantity * Invoice Price)
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
                    'inventory_item_id' => $invoiceLine->inventory_item_id,
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

            // CTO Request: Update Invoice status
            $invoice->update(['status' => VendorInvoiceStatusEnum::Matched]);

            return $match;
        });
    }

    private function createExceptionMatch(VendorInvoice $invoice, MatchExceptionEnum $exception): ThreeWayMatch
    {
        return DB::transaction(function () use ($invoice, $exception) {
            $match = ThreeWayMatch::create([
                'property_id' => $invoice->property_id,
                'vendor_invoice_id' => $invoice->id,
                'purchase_order_id' => $invoice->purchase_order_id,
                'goods_receipt_id' => $invoice->goods_receipt_id,
                'status' => MatchStatusEnum::Exception,
                'exception_code' => $exception,
            ]);

            // CTO Request: Exception remains Submitted
            // So we do not update $invoice->status to Matched

            return $match;
        });
    }
}
