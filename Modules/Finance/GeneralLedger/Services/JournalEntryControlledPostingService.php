<?php

namespace Modules\Finance\GeneralLedger\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierPaymentInstrumentTypeEnum;
use Modules\Operations\GeneralCashier\Models\PaymentExecutionVoidEvidence;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Modules\Operations\GeneralCashier\Services\CashbookTransactionProjectionService;
use RuntimeException;
use Throwable;

class JournalEntryControlledPostingService
{
    public const PERMISSION = 'finance.journal-entry.post';

    public function __construct(
        private readonly GeneralLedgerService $generalLedgerService,
        private readonly CashbookTransactionProjectionService $cashbookProjectionService
    ) {}

    public function post(string $journalEntryId, string $actorId): JournalEntry
    {
        return DB::transaction(function () use ($journalEntryId, $actorId) {
            $actor = $this->resolveAuthorizedActor($actorId);

            $journal = JournalEntry::with('lines')
                ->where('id', $journalEntryId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $journal->property_id);

            if ($journal->status === JournalStatusEnum::Posted) {
                $this->assertPostedReplayMatches($journal, $actor);
                $this->assertApprovedCandidateProvenance($journal);
                $this->assertBalancedLines($journal);

                return $this->loadOrderedLines($journal);
            }

            $this->assertDraftReadyForPosting($journal);
            $this->assertApprovedCandidateProvenance($journal);
            $this->assertBalancedLines($journal);

            $posted = $this->generalLedgerService->postJournalEntry($journal->id, $actor->id);
            if ($this->shouldProjectCashbookTransaction($posted)) {
                $this->cashbookProjectionService->projectPostedCashSupplierPayment($posted, $actor->id);
            }

            return $this->loadOrderedLines($posted);
        });
    }

    private function resolveAuthorizedActor(string $actorId): User
    {
        $actor = User::where('id', $actorId)
            ->where('is_active', true)
            ->first();

        if (!$actor) {
            throw new AuthorizationException('Unauthorized to execute JournalEntry posting.');
        }

        try {
            $authorized = $actor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Unauthorized to execute JournalEntry posting.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Unauthorized to execute JournalEntry posting.');
        }

        return $actor;
    }

    private function assertActorCanAccessProperty(User $actor, string $propertyId): void
    {
        $hasPropertyAccess = $actor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('Unauthorized to execute JournalEntry posting.');
        }
    }

    private function assertPostedReplayMatches(JournalEntry $journal, User $actor): void
    {
        if (
            $journal->posted_by === $actor->id &&
            $journal->posted_at !== null &&
            $journal->posting_date !== null
        ) {
            return;
        }

        throw new RuntimeException('Conflicting JournalEntry posting evidence already exists.');
    }

    private function assertDraftReadyForPosting(JournalEntry $journal): void
    {
        if ($journal->status !== JournalStatusEnum::Draft) {
            throw new RuntimeException('Only Draft JournalEntries can be posted through controlled posting.');
        }

        if ($journal->posting_date !== null || $journal->posted_by !== null || $journal->posted_at !== null) {
            throw new RuntimeException('JournalEntry already contains posting evidence.');
        }

        if ($journal->reversal_of_id !== null) {
            throw new RuntimeException('Reversal JournalEntries cannot be posted through controlled GRNI posting.');
        }

        if ($journal->draft_finalization_authorized_by === null || $journal->draft_finalization_authorized_at === null) {
            throw new RuntimeException('JournalEntry draft must be finalization-authorized before posting.');
        }
    }

    private function assertApprovedCandidateProvenance(JournalEntry $journal): void
    {
        if ($journal->journal_candidate_id === null) {
            throw new RuntimeException('Controlled posting requires JournalCandidate provenance.');
        }

        $candidate = JournalCandidate::where('id', $journal->journal_candidate_id)
            ->lockForUpdate()
            ->first();

        if (!$candidate) {
            throw new RuntimeException('Source JournalCandidate provenance is missing.');
        }

        if (
            $candidate->status !== JournalCandidateStatusEnum::APPROVED ||
            $candidate->approved_by === null ||
            $candidate->approved_at === null
        ) {
            throw new RuntimeException('Only approved JournalCandidate-derived drafts can be posted.');
        }

        if (!$this->isSupportedCandidate($candidate)) {
            throw new RuntimeException('Only supported JournalCandidates can be posted through this action.');
        }

        $this->assertPaymentExecutionNotVoided($candidate);

        if (
            $journal->property_id !== $candidate->property_id ||
            $journal->source_type !== $candidate->source_type ||
            $journal->source_id !== $candidate->source_id ||
            $journal->posting_event !== $candidate->posting_event
        ) {
            throw new RuntimeException('JournalEntry provenance conflicts with source JournalCandidate.');
        }
    }

    private function isSupportedCandidate(JournalCandidate $candidate): bool
    {
        if ($candidate->source_type === 'InventoryReceipt' && $candidate->posting_event === 'InventoryReceiptAccrual') {
            return true;
        }

        return ($candidate->source_type === 'SupplierInvoice'
            && $candidate->posting_event === 'SupplierInvoiceGrniClearingApLiability'
            && $candidate->source_grni_candidate_id !== null
            && $candidate->source_grni_journal_entry_id !== null)
            || ($candidate->source_type === 'PaymentExecution'
            && $candidate->posting_event === 'SupplierPaymentCashDisbursement')
            || ($candidate->source_type === 'CashSupplierPaymentReversalExecution'
            && $candidate->posting_event === 'SupplierPaymentCashReturnReversal');
    }

    private function assertPaymentExecutionNotVoided(JournalCandidate $candidate): void
    {
        if ($candidate->source_type !== 'PaymentExecution' || $candidate->posting_event !== 'SupplierPaymentCashDisbursement') {
            return;
        }

        $voidEvidence = PaymentExecutionVoidEvidence::where('payment_execution_id', $candidate->source_id)
            ->lockForUpdate()
            ->first();

        if ($voidEvidence) {
            throw new RuntimeException('Voided PaymentExecution cannot be posted as a supplier payment JournalEntry.');
        }
    }

    private function assertBalancedLines(JournalEntry $journal): void
    {
        if ($journal->lines->isEmpty()) {
            throw new RuntimeException('JournalEntry has no lines to post.');
        }

        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($journal->lines as $line) {
            if ($line->property_id !== $journal->property_id) {
                throw new RuntimeException('Cross-property JournalEntry lines cannot be posted.');
            }

            if ($line->account_id === null || $line->account_id === '') {
                throw new RuntimeException('JournalEntry line account evidence is missing.');
            }

            $debit = (float) $line->debit_amount;
            $credit = (float) $line->credit_amount;

            if ($debit < 0 || $credit < 0) {
                throw new RuntimeException('JournalEntry line amounts must be non-negative.');
            }

            if (($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) {
                throw new RuntimeException('JournalEntry lines must carry exactly one active debit or credit side.');
            }

            $debitTotal += (int) round($debit * 100);
            $creditTotal += (int) round($credit * 100);
        }

        if ($debitTotal !== $creditTotal) {
            throw new RuntimeException('JournalEntry is out of balance and cannot be posted.');
        }
    }

    private function shouldProjectCashbookTransaction(JournalEntry $journal): bool
    {
        if ($journal->source_type !== 'PaymentExecution' || $journal->posting_event !== 'SupplierPaymentCashDisbursement') {
            return false;
        }

        $execution = PaymentExecution::with('cashierPaymentInstrument')
            ->whereKey($journal->source_id)
            ->lockForUpdate()
            ->firstOrFail();

        return $execution->cashierPaymentInstrument?->type === CashierPaymentInstrumentTypeEnum::CASH;
    }

    private function loadOrderedLines(JournalEntry $journal): JournalEntry
    {
        $journal->setRelation(
            'lines',
            $journal->lines()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
        );

        return $journal;
    }
}
