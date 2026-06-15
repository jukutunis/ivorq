<?php

namespace Modules\Finance\Banking\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Banking\Models\ReconciliationMatch;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Treasury\Models\VendorPayment;
use Modules\Finance\Treasury\Models\FundTransfer;
use Modules\Finance\Treasury\Enums\VendorPaymentStatusEnum;
use Modules\Finance\Banking\Exceptions\ReconciliationCommitException;

class ReconciliationCommitService
{
    public function commit1to1(
        ReconciliationSession $session,
        string $bankLineId,
        string $matchableType,
        string $matchableId,
        float $amountMatched,
        string $matchMethod,
        string $userId,
        ?string $reason = null
    ): ReconciliationMatch {
        if ($amountMatched <= 0) {
            throw ReconciliationCommitException::invalidAmount($amountMatched);
        }

        return DB::transaction(function () use ($session, $bankLineId, $matchableType, $matchableId, $amountMatched, $matchMethod, $userId, $reason) {
            
            // 1. Lock Bank Line
            $bankLine = BankStatementLine::where('id', $bankLineId)->lockForUpdate()->firstOrFail();
            
            // 2. Lock Matchable
            /** @var \Illuminate\Database\Eloquent\Model $matchable */
            $matchable = $matchableType::where('id', $matchableId)->lockForUpdate()->firstOrFail();

            // 3. Immutability & Over-Allocation Guard (Bank Line)
            $existingBankMatched = ReconciliationMatch::where('bank_statement_line_id', $bankLineId)->sum('amount_matched');
            $remainingBankLine = abs($bankLine->amount) - $existingBankMatched;

            // Using round to prevent float precision issues
            if (round($amountMatched, 2) > round($remainingBankLine, 2)) {
                throw ReconciliationCommitException::overAllocation('BankStatementLine', $amountMatched, $remainingBankLine);
            }

            // 4. Immutability & Over-Allocation Guard (Matchable)
            $targetAmount = 0.0;
            if ($matchable instanceof VendorPayment) {
                $targetAmount = $matchable->total_amount;
                if ($matchable->status === VendorPaymentStatusEnum::Reconciled) {
                    throw ReconciliationCommitException::alreadyReconciled('VendorPayment');
                }
            } elseif ($matchable instanceof FundTransfer) {
                $targetAmount = $matchable->amount;
                // Check if already fully reconciled via matches
                $isFullyMatched = ReconciliationMatch::where('matchable_type', $matchableType)
                    ->where('matchable_id', $matchableId)
                    ->sum('amount_matched') >= $targetAmount;
                if ($isFullyMatched) {
                    throw ReconciliationCommitException::alreadyReconciled('FundTransfer');
                }
            }

            $existingMatchableMatched = ReconciliationMatch::where('matchable_type', $matchableType)
                ->where('matchable_id', $matchableId)
                ->sum('amount_matched');
            
            $remainingMatchable = abs($targetAmount) - $existingMatchableMatched;

            if (round($amountMatched, 2) > round($remainingMatchable, 2)) {
                throw ReconciliationCommitException::overAllocation('Matchable', $amountMatched, $remainingMatchable);
            }

            // 5. Check if fully matched after this commit
            $newBankMatched = round($existingBankMatched + $amountMatched, 2);
            if ($newBankMatched >= round(abs($bankLine->amount), 2)) {
                $bankLine->update(['is_reconciled' => true]);
            }

            $newMatchableMatched = round($existingMatchableMatched + $amountMatched, 2);
            if ($newMatchableMatched >= round(abs($targetAmount), 2)) {
                if ($matchable instanceof VendorPayment) {
                    $matchable->update(['status' => VendorPaymentStatusEnum::Reconciled]);
                }
            }

            // 6. Create Match
            return ReconciliationMatch::create([
                'property_id' => $session->property_id,
                'reconciliation_session_id' => $session->id,
                'bank_statement_line_id' => $bankLine->id,
                'matchable_type' => $matchableType,
                'matchable_id' => $matchableId,
                'amount_matched' => $amountMatched,
                'matchable_amount' => abs($targetAmount),
                'statement_amount' => abs($bankLine->amount),
                'match_method' => $matchMethod,
                'matched_by' => $userId,
                'override_reason' => $reason,
                'is_locked' => true,
                'bank_account_balance_before' => 0, // Simplified for this context
                'bank_account_balance_after' => 0, // Simplified for this context
            ]);
        });
    }

    public function commitSplit(
        ReconciliationSession $session,
        string $bankLineId,
        array $allocations, // [['type' => type, 'id' => id, 'amount' => amount]]
        string $userId
    ): array {
        return DB::transaction(function () use ($session, $bankLineId, $allocations, $userId) {
            $matches = [];
            foreach ($allocations as $allocation) {
                $matches[] = $this->commit1to1(
                    $session,
                    $bankLineId,
                    $allocation['type'],
                    $allocation['id'],
                    $allocation['amount'],
                    'SPLIT',
                    $userId
                );
            }
            return $matches;
        });
    }

    public function commitMerge(
        ReconciliationSession $session,
        array $bankLineAllocations, // [['id' => bank_line_id, 'amount' => amount]]
        string $matchableType,
        string $matchableId,
        string $userId
    ): array {
        return DB::transaction(function () use ($session, $bankLineAllocations, $matchableType, $matchableId, $userId) {
            $matches = [];
            foreach ($bankLineAllocations as $line) {
                $matches[] = $this->commit1to1(
                    $session,
                    $line['id'],
                    $matchableType,
                    $matchableId,
                    $line['amount'],
                    'MERGE',
                    $userId
                );
            }
            return $matches;
        });
    }
}
