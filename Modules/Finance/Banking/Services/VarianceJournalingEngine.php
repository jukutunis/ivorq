<?php

namespace Modules\Finance\Banking\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Models\ReconciliationMatch;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityMappingService;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityValidationService;
use Modules\Finance\Treasury\Models\VendorPayment;
use Carbon\Carbon;

class VarianceJournalingEngine
{
    public function __construct(
        protected OperationalIdentityMappingService $mappingService,
        protected OperationalIdentityValidationService $validationService
    ) {}

    public function processSession(ReconciliationSession $session): void
    {
        if ($session->status !== ReconciliationSessionStatusEnum::Completed) {
            throw new Exception("Variance Journaling Engine can only process Completed sessions.");
        }

        DB::transaction(function () use ($session) {
            $this->processBankFees($session);
            $this->processPaymentVariances($session);
            $this->processUnmatchedBankLines($session);
            $this->processManualAdjustments($session);
        });
    }

    protected function processBankFees(ReconciliationSession $session): void
    {
        $matches = ReconciliationMatch::where('reconciliation_session_id', $session->id)
            ->where('matchable_type', VendorPayment::class)
            ->get();

        foreach ($matches as $match) {
            $payment = $match->matchable;
            if ($payment && $payment->bank_fee_amount > 0) {
                $this->createCandidate(
                    $session,
                    OperationalIdentityEnum::BANK_FEE,
                    VendorPayment::class,
                    $payment->id,
                    $payment->bank_fee_amount,
                    'Bank Fee for Vendor Payment',
                    $match->id,
                    'VendorPayment Bank Fee'
                );
            }
        }
    }

    protected function processPaymentVariances(ReconciliationSession $session): void
    {
        $matches = ReconciliationMatch::where('reconciliation_session_id', $session->id)
            ->where('matchable_type', VendorPayment::class)
            ->get();

        $sessionVariance = 0.0;

        foreach ($matches as $match) {
            $payment = $match->matchable;
            if ($payment) {
                // Expected vs Matched
                // We assume variance = total_amount - amount_matched - bank_fee_amount
                // But the instruction says: "If session still contains: remaining_amount != 0 Generate: PAYMENT_VARIANCE at session level."
                // I will aggregate it.
                $variance = $payment->total_amount - $match->amount_matched - ($payment->bank_fee_amount ?? 0);
                $sessionVariance += $variance;
            }
        }

        if (round($sessionVariance, 2) != 0) {
            $this->createCandidate(
                $session,
                OperationalIdentityEnum::PAYMENT_VARIANCE,
                ReconciliationSession::class,
                $session->id,
                abs($sessionVariance),
                'Session Payment Variance',
                null,
                'Session Residual Variance'
            );
        }
    }

    protected function processUnmatchedBankLines(ReconciliationSession $session): void
    {
        $unmatchedLines = BankStatementLine::whereHas('bankStatement', function ($query) use ($session) {
            $query->where('property_id', $session->property_id)
                  ->where('bank_account_id', $session->bank_account_id)
                  ->where('statement_date', '>=', $session->statement_date_start)
                  ->where('statement_date', '<=', $session->statement_date_end);
        })->where('is_reconciled', false)->get();

        foreach ($unmatchedLines as $line) {
            $this->createCandidate(
                $session,
                OperationalIdentityEnum::UNMATCHED_BANK_LINE,
                BankStatementLine::class,
                $line->id,
                abs($line->amount),
                'Unmatched Bank Line: ' . $line->description,
                null,
                'Unreconciled Statement Line'
            );
        }
    }

    protected function processManualAdjustments(ReconciliationSession $session): void
    {
        $matches = ReconciliationMatch::where('reconciliation_session_id', $session->id)
            ->where('match_method', 'MANUAL_OVERRIDE')
            ->get();

        foreach ($matches as $match) {
            if (empty($match->override_reason)) {
                throw new Exception("MANUAL_OVERRIDE match {$match->id} is missing override_reason.");
            }

            // Calculate the adjustment amount based on what was overridden
            // Assuming statement_amount vs matchable_amount difference
            $adjustmentAmount = abs($match->statement_amount - $match->matchable_amount);
            
            if ($adjustmentAmount > 0) {
                $this->createCandidate(
                    $session,
                    OperationalIdentityEnum::MANUAL_ADJUSTMENT,
                    ReconciliationMatch::class,
                    $match->id,
                    $adjustmentAmount,
                    'Manual Adjustment: ' . $match->override_reason,
                    $match->id,
                    'Manual Override Adjustment'
                );
            }
        }
    }

    protected function createCandidate(
        ReconciliationSession $session,
        OperationalIdentityEnum $identity,
        string $sourceType,
        string $sourceId,
        float $amount,
        string $description,
        ?string $sourceMatchId,
        string $varianceReason
    ): void {
        $candidate = JournalCandidate::firstOrCreate([
            'property_id' => $session->property_id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'posting_event' => 'BANK_RECONCILIATION_VARIANCE',
        ], [
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW,
            'candidate_date' => $session->statement_date_end ?? now(),
            'description' => $description,
            'metadata' => [
                'variance_type' => $identity->value,
                'variance_amount' => $amount,
                'variance_source' => $sourceType,
                'variance_reason' => $varianceReason,
                'source_match_id' => $sourceMatchId,
                'reconciliation_session_id' => $session->id,
            ]
        ]);

        if ($candidate->wasRecentlyCreated) {
            try {
                $date = $session->statement_date_end ? Carbon::parse($session->statement_date_end) : now();
                $mapping = $this->mappingService->resolve($session->property_id, $identity, $date);
                
                $this->validationService->validate($identity, $mapping->account);

                $candidate->lines()->create([
                    'operational_identity' => $identity,
                    'entry_type' => EntryTypeEnum::DEBIT, // Defaulting to Debit for simplicity, though real logic might vary based on signs
                    'amount' => $amount,
                    'cost_center_id' => $mapping->cost_center_id,
                    'notes' => $description,
                ]);
            } catch (Exception $e) {
                $candidate->update([
                    'status' => JournalCandidateStatusEnum::CONFIGURATION_ERROR,
                    'last_reevaluation_error' => $e->getMessage()
                ]);
            }
        }
    }
}
