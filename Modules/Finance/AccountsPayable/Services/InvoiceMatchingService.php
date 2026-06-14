<?php

namespace Modules\Finance\AccountsPayable\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\AccountsPayable\Exceptions\InvoiceMatchingException;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Finance\AccountsPayable\Models\ApInvoiceLine;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceTypeEnum;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;

class InvoiceMatchingService
{
    public function __construct(
        private InvoiceVarianceCalculationEngine $varianceEngine
    ) {}

    public function processInvoice(ApInvoice $invoice): array
    {
        if ($invoice->invoice_type === ApInvoiceTypeEnum::DIRECT_EXPENSE) {
            // DIRECT_EXPENSE invoices must bypass matching
            return [
                'status' => 'bypassed',
                'message' => 'Direct expense invoices bypass GRNI matching.'
            ];
        }

        return DB::transaction(function () use ($invoice) {
            $results = [];

            foreach ($invoice->lines as $line) {
                $results[] = $this->matchLine($invoice, $line);
            }

            return [
                'status' => 'matched',
                'lines' => $results
            ];
        });
    }

    public function matchLine(ApInvoice $invoice, ApInvoiceLine $invoiceLine): array
    {
        if (!$invoiceLine->receipt_line_id) {
            throw InvoiceMatchingException::receiptLineMissing();
        }

        $receiptLine = InventoryReceiptLine::lockForUpdate()->findOrFail($invoiceLine->receipt_line_id);

        if ($receiptLine->property_id !== $invoice->property_id) {
            throw InvoiceMatchingException::propertyMismatch();
        }

        $newInvoicedQuantity = $receiptLine->invoiced_quantity + $invoiceLine->quantity;

        // Prevent invoiced_quantity > received_quantity (which is `quantity` in the db)
        if ($newInvoicedQuantity > $receiptLine->quantity) {
            throw InvoiceMatchingException::invoicedQuantityExceedsReceived();
        }

        $varianceResult = $this->varianceEngine->calculate(
            $invoiceLine->quantity,
            $invoiceLine->unit_price,
            $receiptLine->unit_cost
        );

        // Update Receipt Tracking
        $receiptLine->invoiced_quantity = $newInvoicedQuantity;
        $receiptLine->invoiced_amount += $varianceResult['matched_amount'];
        $receiptLine->save();

        $matchType = $this->determineMatchType($invoiceLine->quantity, $receiptLine->quantity, $newInvoicedQuantity);

        return [
            'invoice_line_id' => $invoiceLine->id,
            'receipt_line_id' => $receiptLine->id,
            'match_type' => $matchType,
            'matched_amount' => $varianceResult['matched_amount'],
            'variance_amount' => $varianceResult['variance_amount'],
            'variance_percent' => $varianceResult['variance_percent'],
        ];
    }

    private function determineMatchType(float $invoicedQuantity, float $totalReceiptQuantity, float $newTotalInvoicedQuantity): string
    {
        if ($newTotalInvoicedQuantity === $totalReceiptQuantity) {
            return 'FULL_MATCH';
        }

        if ($newTotalInvoicedQuantity < $totalReceiptQuantity) {
            return 'PARTIAL_MATCH';
        }

        if ($invoicedQuantity > $totalReceiptQuantity) {
            // While we throw an exception above if $newTotalInvoicedQuantity > $totalReceiptQuantity,
            // we maintain the logic here if rules change later for OVER_INVOICE.
            return 'OVER_INVOICE';
        }

        return 'UNKNOWN';
    }
}
