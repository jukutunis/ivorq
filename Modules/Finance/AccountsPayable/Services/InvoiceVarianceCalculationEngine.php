<?php

namespace Modules\Finance\AccountsPayable\Services;

class InvoiceVarianceCalculationEngine
{
    public function calculate(
        float $invoicedQuantity,
        float $invoicedUnitPrice,
        float $receiptUnitCost
    ): array {
        // Matched amount is the value of the goods at the original receipt cost
        $matchedAmount = round($invoicedQuantity * $receiptUnitCost, 3);
        
        // Invoiced amount is the actual amount charged by the vendor
        $invoicedAmount = round($invoicedQuantity * $invoicedUnitPrice, 3);
        
        // Variance is the difference between the invoiced amount and matched amount
        // Positive variance means we paid more than expected
        // Negative variance means we paid less than expected
        $varianceAmount = round($invoicedAmount - $matchedAmount, 3);
        
        $variancePercent = 0.0;
        if ($matchedAmount > 0) {
            $variancePercent = round(($varianceAmount / $matchedAmount) * 100, 2);
        }

        return [
            'matched_amount' => $matchedAmount,
            'variance_amount' => $varianceAmount,
            'variance_percent' => $variancePercent,
        ];
    }
}
