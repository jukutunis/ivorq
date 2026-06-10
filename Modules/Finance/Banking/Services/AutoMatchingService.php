<?php

namespace Modules\Finance\Banking\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Payables\Models\PaymentVoucher;

class AutoMatchingService
{
    /**
     * Generate auto-match recommendations for a session.
     * BR-017: AutoMatch never writes to database.
     * Returns a JSON-serializable array of recommendations.
     */
    public function getRecommendations(ReconciliationSession $session): array
    {
        $recommendations = [];

        // 1. Get unmatched BankStatementLines for the session's bank account and date range
        $unmatchedLines = BankStatementLine::where('property_id', $session->property_id)
            ->whereHas('bankStatement', function ($query) use ($session) {
                $query->where('bank_account_id', $session->bank_account_id);
            })
            ->whereBetween('transaction_date', [$session->statement_date_start, $session->statement_date_end])
            ->where('is_reconciled', false)
            ->whereDoesntHave('reconciliationMatch') // ensure not matched
            ->get();

        if ($unmatchedLines->isEmpty()) {
            return [];
        }

        // 2. Get unmatched PaymentVouchers within session start - 30 days and session end + 30 days
        $startDate = Carbon::parse($session->statement_date_start)->subDays(30);
        $endDate = Carbon::parse($session->statement_date_end)->addDays(30);

        $unmatchedVouchers = PaymentVoucher::where('property_id', $session->property_id)
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->whereIn('status', ['Posted']) // Assuming Posted vouchers are available for matching
            ->whereDoesntHave('reconciliationMatch') // ensure not matched
            ->get();

        if ($unmatchedVouchers->isEmpty()) {
            return [];
        }

        $matchedLineIds = [];
        $matchedVoucherIds = [];

        // Execute Rule 1: Exact Amount & Exact Reference
        foreach ($unmatchedLines as $line) {
            $absAmount = abs($line->amount);

            $candidates = $unmatchedVouchers->filter(function ($voucher) use ($absAmount, $line, $matchedVoucherIds) {
                if (in_array($voucher->id, $matchedVoucherIds)) {
                    return false;
                }

                // Reference exact match
                if (empty($line->reference) || empty($voucher->reference_no)) {
                    return false;
                }

                if ($line->reference !== $voucher->reference_no) {
                    return false;
                }

                // Amount exact match (bccomp)
                return bccomp((string) $absAmount, (string) $voucher->total_amount, 2) === 0;
            });

            if ($candidates->count() === 1) {
                $voucher = $candidates->first();
                $recommendations[] = [
                    'bank_statement_line_id' => $line->id,
                    'matchable_type' => PaymentVoucher::class,
                    'matchable_id' => $voucher->id,
                    'rule_applied' => 'ExactMatch',
                ];
                $matchedLineIds[] = $line->id;
                $matchedVoucherIds[] = $voucher->id;
            }
            // If candidates->count() > 1, it's ambiguous, skip.
        }

        // Execute Rule 2: Date Tolerance Match
        foreach ($unmatchedLines as $line) {
            if (in_array($line->id, $matchedLineIds)) {
                continue;
            }

            $absAmount = abs($line->amount);
            $lineDate = Carbon::parse($line->transaction_date);

            $candidates = $unmatchedVouchers->filter(function ($voucher) use ($absAmount, $lineDate, $matchedVoucherIds) {
                if (in_array($voucher->id, $matchedVoucherIds)) {
                    return false;
                }

                // Amount exact match
                if (bccomp((string) $absAmount, (string) $voucher->total_amount, 2) !== 0) {
                    return false;
                }

                // Date tolerance (±2 days)
                $voucherDate = Carbon::parse($voucher->payment_date);
                $diff = $voucherDate->diffInDays($lineDate);

                return $diff <= 2;
            });

            if ($candidates->count() === 1) {
                $voucher = $candidates->first();
                $recommendations[] = [
                    'bank_statement_line_id' => $line->id,
                    'matchable_type' => PaymentVoucher::class,
                    'matchable_id' => $voucher->id,
                    'rule_applied' => 'DateToleranceMatch',
                ];
                $matchedLineIds[] = $line->id;
                $matchedVoucherIds[] = $voucher->id;
            }
            // If candidates->count() > 1, it's ambiguous, skip.
        }

        return $recommendations;
    }
}
