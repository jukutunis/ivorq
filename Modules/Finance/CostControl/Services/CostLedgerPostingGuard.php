<?php
namespace Modules\Finance\CostControl\Services;

use Modules\Finance\CostControl\ValueObjects\ApprovedInventoryEvidence;
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingWindow;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationState;
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingDecision;

class CostLedgerPostingGuard
{
    public function evaluate(
        ApprovedInventoryEvidence $evidence,
        CostLedgerPostingWindow $window,
        AvcoValuationState $priorState
    ): CostLedgerPostingDecision {
        if ($evidence->propertyId !== $window->propertyId) {
            return new CostLedgerPostingDecision(CostLedgerPostingDecision::STATUS_REJECTED, 'PROPERTY_MISMATCH_WITH_WINDOW', true);
        }
        if ($priorState->propertyId !== $evidence->propertyId) {
            return new CostLedgerPostingDecision(CostLedgerPostingDecision::STATUS_REJECTED, 'PROPERTY_MISMATCH_WITH_STATE', true);
        }
        if ($priorState->itemId !== $evidence->itemId) {
            return new CostLedgerPostingDecision(CostLedgerPostingDecision::STATUS_REJECTED, 'ITEM_MISMATCH_WITH_STATE', true);
        }
        if ($priorState->valuationScope !== $evidence->valuationScope) {
            return new CostLedgerPostingDecision(CostLedgerPostingDecision::STATUS_REJECTED, 'SCOPE_MISMATCH_WITH_STATE', true);
        }
        if ($evidence->sourceBusinessDate !== $window->sourceBusinessDate) {
            return new CostLedgerPostingDecision(CostLedgerPostingDecision::STATUS_REJECTED, 'BUSINESS_DATE_MISMATCH_WITH_WINDOW', true);
        }

        if ($evidence->approvalStatus !== 'approved' || empty($evidence->approvalReference)) {
            return new CostLedgerPostingDecision(CostLedgerPostingDecision::STATUS_REJECTED, 'SOURCE_NOT_APPROVED', true);
        }

        if (!$window->isPropertyBusinessDateOpen) {
            if ($window->currentOpenCorrectionBusinessDate !== null && $window->currentOpenCorrectionFinancialPeriodId !== null) {
                return new CostLedgerPostingDecision(
                    CostLedgerPostingDecision::STATUS_CORRECTION_REQUIRED,
                    'SOURCE_BUSINESS_DATE_CLOSED',
                    true,
                    $window->currentOpenCorrectionBusinessDate,
                    $window->currentOpenCorrectionFinancialPeriodId,
                    $evidence->sourceTransactionReference,
                    $evidence->sourceBusinessDate
                );
            }
            return new CostLedgerPostingDecision(CostLedgerPostingDecision::STATUS_REJECTED, 'MISSING_OPEN_CORRECTION_CONTEXT', true, null, null, $evidence->sourceTransactionReference, $evidence->sourceBusinessDate);
        }

        if (!$window->isFinancialPeriodOpen) {
            if ($window->currentOpenCorrectionBusinessDate !== null && $window->currentOpenCorrectionFinancialPeriodId !== null) {
                return new CostLedgerPostingDecision(
                    CostLedgerPostingDecision::STATUS_CORRECTION_REQUIRED,
                    'SOURCE_FINANCIAL_PERIOD_CLOSED',
                    true,
                    $window->currentOpenCorrectionBusinessDate,
                    $window->currentOpenCorrectionFinancialPeriodId,
                    $evidence->sourceTransactionReference,
                    $evidence->sourceBusinessDate
                );
            }
            return new CostLedgerPostingDecision(CostLedgerPostingDecision::STATUS_REJECTED, 'MISSING_OPEN_CORRECTION_CONTEXT', true, null, null, $evidence->sourceTransactionReference, $evidence->sourceBusinessDate);
        }

        return new CostLedgerPostingDecision(CostLedgerPostingDecision::STATUS_ALLOW, 'ELIGIBLE');
    }
}