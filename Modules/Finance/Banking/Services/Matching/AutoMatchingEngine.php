<?php

namespace Modules\Finance\Banking\Services\Matching;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Modules\Finance\Banking\DTOs\MatchCandidateDTO;
use Modules\Finance\Banking\DTOs\MatchResultDTO;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Treasury\Models\VendorPayment;
use Modules\Finance\Treasury\Models\FundTransfer;
use Modules\Finance\Treasury\Enums\VendorPaymentStatusEnum;

class AutoMatchingEngine extends AbstractMatchingEngine
{
    public function findCandidates(BankStatementLine $line): array
    {
        $candidates = [];

        // Determine date bounds
        $date = Carbon::parse($line->transaction_date);
        $minDate = $date->copy()->subDays($this->config->date_tolerance_days)->format('Y-m-d');
        $maxDate = $date->copy()->addDays($this->config->date_tolerance_days)->format('Y-m-d');

        // Determine amount bounds
        $absAmount = abs($line->amount);
        $minAmount = $absAmount - $this->config->amount_tolerance;
        $maxAmount = $absAmount + $this->config->amount_tolerance;

        if ($line->amount < 0) {
            // Outflows (-): VendorPayments, Outbound FundTransfers
            
            // 1. Vendor Payments
            $payments = VendorPayment::where('property_id', $line->property_id)
                ->whereNotIn('status', [
                    VendorPaymentStatusEnum::Reconciled->value,
                    VendorPaymentStatusEnum::Voided->value,
                    VendorPaymentStatusEnum::Cancelled->value,
                    VendorPaymentStatusEnum::Draft->value
                ])
                ->whereBetween('payment_date', [$minDate, $maxDate])
                ->whereBetween('total_amount', [$minAmount, $maxAmount])
                ->get();

            foreach ($payments as $payment) {
                $candidate = new MatchCandidateDTO(
                    VendorPayment::class,
                    $payment->id,
                    $line->id
                );
                $candidates[] = $this->scoreCandidate($line, $candidate, $payment);
            }

            // 2. Outbound Fund Transfers
            $transfersOut = FundTransfer::where('property_id', $line->property_id)
                ->whereHas('sourceBankAccount', function (Builder $query) use ($line) {
                    $query->where('id', $line->bankStatement->bank_account_id);
                })
                ->whereBetween('transfer_date', [$minDate, $maxDate])
                ->whereBetween('amount', [$minAmount, $maxAmount])
                ->get();

            foreach ($transfersOut as $transfer) {
                $candidate = new MatchCandidateDTO(
                    FundTransfer::class,
                    $transfer->id,
                    $line->id
                );
                $candidates[] = $this->scoreCandidate($line, $candidate, $transfer);
            }

        } else {
            // Inflows (+): Inbound FundTransfers, future GeneralCashierReceipts
            
            // 1. Inbound Fund Transfers
            $transfersIn = FundTransfer::where('property_id', $line->property_id)
                ->whereHas('destinationBankAccount', function (Builder $query) use ($line) {
                    $query->where('id', $line->bankStatement->bank_account_id);
                })
                ->whereBetween('transfer_date', [$minDate, $maxDate])
                ->whereBetween('amount', [$minAmount, $maxAmount])
                ->get();

            foreach ($transfersIn as $transfer) {
                $candidate = new MatchCandidateDTO(
                    FundTransfer::class,
                    $transfer->id,
                    $line->id
                );
                $candidates[] = $this->scoreCandidate($line, $candidate, $transfer);
            }
        }

        // Rank candidates highest confidence first
        usort($candidates, function ($a, $b) {
            return $b->total_score <=> $a->total_score;
        });

        return $candidates;
    }

    public function scoreCandidate(BankStatementLine $line, MatchCandidateDTO $candidate, $model = null): MatchCandidateDTO
    {
        if (!$model) {
            $model = $candidate->matchable_type::find($candidate->matchable_id);
        }

        if (!$model) {
            $candidate->setScores(0, 0, 0, 0);
            return $candidate;
        }

        // Extract model attributes dynamically based on type
        $treasuryAmount = 0.0;
        $treasuryDate = Carbon::now();
        $treasuryReference = null;

        if ($model instanceof VendorPayment) {
            $treasuryAmount = $model->total_amount;
            $treasuryDate = Carbon::parse($model->payment_date);
            $treasuryReference = $model->payment_number ?? $model->reference; // Assuming generic reference field if any
        } elseif ($model instanceof FundTransfer) {
            $treasuryAmount = $model->amount;
            $treasuryDate = Carbon::parse($model->transfer_date);
            $treasuryReference = $model->transfer_number ?? $model->reference;
        }

        $amountScore = $this->calculateAmountScore($line->amount, $treasuryAmount);
        $dateScore = $this->calculateDateScore(Carbon::parse($line->transaction_date), $treasuryDate);
        
        $referenceScore = 0.0;
        // If the treasury record doesn't have a reference, we just score it 0 and rely on Date+Amount
        if (!empty($treasuryReference) && !empty($line->reference)) {
            $referenceScore = $this->calculateReferenceScore($line->reference, $treasuryReference);
        } elseif (!empty($treasuryReference) && !empty($line->description)) {
            // Fallback to description if reference is empty
            $referenceScore = $this->calculateReferenceScore($line->description, $treasuryReference);
        }

        $totalScore = $this->calculateConfidence($amountScore, $dateScore, $referenceScore);

        $candidate->setScores($amountScore, $dateScore, $referenceScore, $totalScore);

        return $candidate;
    }

    public function evaluate(BankStatementLine $line, array $candidates): MatchResultDTO
    {
        if (empty($candidates)) {
            return new MatchResultDTO(false, 0.0, 0.0, 0.0, 0.0, 'No candidates found.', null);
        }

        // Candidates are expected to be pre-sorted by total_score descending
        $best = $candidates[0];

        $isMatch = false;
        $reason = '';

        if ($best->total_score == 100.0) {
            $isMatch = true;
            $reason = 'Exact Match';
        } elseif ($best->total_score >= 95.0) {
            $isMatch = true;
            $reason = 'Auto Match Candidate';
        } elseif ($best->total_score >= 80.0 && $best->total_score < 95.0) {
            $isMatch = false; // Usually not automatically committed, requires review
            $reason = 'Suggested Match';
        } else {
            $isMatch = false;
            $reason = 'Manual Review Required';
        }

        return new MatchResultDTO(
            is_match: $isMatch,
            confidence_score: $best->total_score,
            amount_score: $best->amount_score,
            date_score: $best->date_score,
            reference_score: $best->reference_score,
            reason: $reason,
            candidate: $best
        );
    }
}
