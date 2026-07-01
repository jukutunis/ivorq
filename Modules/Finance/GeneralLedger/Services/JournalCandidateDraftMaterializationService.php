<?php

namespace Modules\Finance\GeneralLedger\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Models\JournalEntryLine;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\PaymentExecutionVoidEvidence;
use RuntimeException;
use Throwable;

class JournalCandidateDraftMaterializationService
{
    public const PERMISSION = 'finance.journal-candidate.materialize-draft';

    public function __construct(
        private readonly OperationalIdentityMappingService $mappingService
    ) {}

    public function materialize(string $candidateId, string $actorId): JournalEntry
    {
        return DB::transaction(function () use ($candidateId, $actorId) {
            $actor = $this->resolveAuthorizedActor($actorId);

            $candidate = JournalCandidate::where('id', $candidateId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $candidate->property_id);

            if ($candidate->status !== JournalCandidateStatusEnum::APPROVED) {
                throw new RuntimeException('Only APPROVED supported journal candidates can be materialized as draft JournalEntries.');
            }

            $this->assertSupportedCandidateType($candidate);
            $this->assertPaymentExecutionNotVoided($candidate);

            if (strlen((string) $candidate->source_id) > 26) {
                throw new RuntimeException('Candidate source_id is too long for JournalEntry provenance.');
            }

            $candidateLines = $candidate->lines()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
            $expectedLines = $this->expectedJournalLines($candidate, $candidateLines);

            $existing = JournalEntry::where('journal_candidate_id', $candidate->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingDraftMatches($existing, $candidate, $expectedLines, $actor->id);

                return $this->loadOrderedLines($existing);
            }

            $this->assertNoProvenanceCollision($candidate);

            $journal = new JournalEntry([
                'property_id' => $candidate->property_id,
                'transaction_date' => $candidate->candidate_date->toDateString(),
                'description' => $candidate->description,
                'status' => JournalStatusEnum::Draft,
                'source_module' => $this->sourceModuleForCandidate($candidate),
                'source_type' => $candidate->source_type,
                'source_id' => $candidate->source_id,
                'journal_candidate_id' => $candidate->id,
                'posting_event' => $candidate->posting_event,
            ]);
            $journal->created_by = $actor->id;
            $journal->updated_by = $actor->id;
            $journal->save();

            foreach ($expectedLines as $expectedLine) {
                $line = new JournalEntryLine([
                    'property_id' => $candidate->property_id,
                    'account_id' => $expectedLine['account_id'],
                    'department_id' => $expectedLine['department_id'],
                    'debit_amount' => $expectedLine['debit_amount'],
                    'credit_amount' => $expectedLine['credit_amount'],
                    'memo' => $expectedLine['memo'],
                ]);
                $line->created_by = $actor->id;
                $line->updated_by = $actor->id;

                $journal->lines()->save($line);
            }

            return $this->loadOrderedLines($journal);
        });
    }

    private function resolveAuthorizedActor(string $actorId): User
    {
        $actor = User::where('id', $actorId)
            ->where('is_active', true)
            ->first();

        if (!$actor) {
            throw new AuthorizationException('Unauthorized to materialize journal candidate drafts.');
        }

        try {
            $authorized = $actor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Unauthorized to materialize journal candidate drafts.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Unauthorized to materialize journal candidate drafts.');
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
            throw new AuthorizationException('Unauthorized to materialize journal candidate drafts.');
        }
    }

    private function assertSupportedCandidateType(JournalCandidate $candidate): void
    {
        if (
            ($candidate->source_type === 'InventoryReceipt' && $candidate->posting_event === 'InventoryReceiptAccrual') ||
            ($candidate->source_type === 'SupplierInvoice' && $candidate->posting_event === 'SupplierInvoiceGrniClearingApLiability') ||
            ($candidate->source_type === 'PaymentExecution' && $candidate->posting_event === 'SupplierPaymentCashDisbursement')
        ) {
            return;
        }

        throw new RuntimeException('Only approved supported journal candidates can be materialized as draft JournalEntries.');
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
            throw new RuntimeException('Voided PaymentExecution cannot be materialized as a supplier payment JournalEntry draft.');
        }
    }

    private function sourceModuleForCandidate(JournalCandidate $candidate): string
    {
        return match ($candidate->source_type) {
            'SupplierInvoice' => 'Payables',
            'PaymentExecution' => 'GeneralCashier',
            default => 'Inventory',
        };
    }

    /**
     * @param Collection<int, \Modules\Finance\GeneralLedger\Models\JournalCandidateLine> $candidateLines
     * @return array<int, array{account_id: string, department_id: ?string, debit_amount: float|int, credit_amount: float|int, memo: ?string}>
     */
    private function expectedJournalLines(JournalCandidate $candidate, Collection $candidateLines): array
    {
        if ($candidateLines->isEmpty()) {
            throw new RuntimeException('Candidate has no lines and cannot be materialized.');
        }

        $debitTotal = 0;
        $creditTotal = 0;
        $expectedLines = [];

        foreach ($candidateLines as $line) {
            $amount = (float) $line->amount;
            if ($amount <= 0) {
                throw new RuntimeException("Candidate line amount must be positive. Got {$amount}.");
            }

            $roundedAmount = round($amount, 2);
            if ($roundedAmount <= 0) {
                throw new RuntimeException("Rounded candidate line amount must be positive. Got {$roundedAmount}.");
            }

            if (number_format($amount, 4, '.', '') !== number_format($roundedAmount, 4, '.', '')) {
                throw new RuntimeException("Candidate line amount {$amount} is not representable as a 2-decimal ledger amount.");
            }

            if ($line->entry_type === EntryTypeEnum::DEBIT) {
                $debitAmount = $roundedAmount;
                $creditAmount = 0;
                $debitTotal += (int) round($roundedAmount * 100);
            } elseif ($line->entry_type === EntryTypeEnum::CREDIT) {
                $debitAmount = 0;
                $creditAmount = $roundedAmount;
                $creditTotal += (int) round($roundedAmount * 100);
            } else {
                throw new RuntimeException("Invalid entry type: '{$line->entry_type->value}'.");
            }

            $mapping = $this->mappingService->resolve(
                $candidate->property_id,
                $line->operational_identity,
                $candidate->candidate_date
            );

            $expectedLines[] = [
                'account_id' => $mapping->account_id,
                'department_id' => $line->cost_center_id,
                'debit_amount' => $debitAmount,
                'credit_amount' => $creditAmount,
                'memo' => $line->notes,
            ];
        }

        if ($debitTotal !== $creditTotal) {
            throw new RuntimeException('Candidate is out of balance. Debits: ' . ($debitTotal / 100) . ', Credits: ' . ($creditTotal / 100));
        }

        return $expectedLines;
    }

    private function assertExistingDraftMatches(JournalEntry $journal, JournalCandidate $candidate, array $expectedLines, string $actorId): void
    {
        if ($journal->status !== JournalStatusEnum::Draft) {
            throw new RuntimeException('Existing JournalEntry for candidate is not a Draft.');
        }

        if ($journal->created_by !== $actorId) {
            throw new RuntimeException('Existing JournalEntry draft was created by a different actor.');
        }

        $transactionDate = $journal->transaction_date?->toDateString() ?? (string) $journal->transaction_date;

        if (
            $journal->property_id !== $candidate->property_id ||
            $transactionDate !== $candidate->candidate_date->toDateString() ||
            $journal->description !== $candidate->description ||
            $journal->source_module !== $this->sourceModuleForCandidate($candidate) ||
            $journal->source_type !== $candidate->source_type ||
            $journal->source_id !== $candidate->source_id ||
            $journal->journal_candidate_id !== $candidate->id ||
            $journal->posting_event !== $candidate->posting_event ||
            $journal->posting_date !== null
        ) {
            throw new RuntimeException('Existing JournalEntry draft conflicts with candidate provenance.');
        }

        $journalLines = $journal->lines()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($journalLines->count() !== count($expectedLines)) {
            throw new RuntimeException('Existing JournalEntry draft line count conflicts with candidate lines.');
        }

        foreach ($journalLines->values() as $index => $journalLine) {
            $expectedLine = $expectedLines[$index];

            if (
                $journalLine->property_id !== $candidate->property_id ||
                $journalLine->account_id !== $expectedLine['account_id'] ||
                $journalLine->department_id !== $expectedLine['department_id'] ||
                $this->amountToCents($journalLine->debit_amount) !== $this->amountToCents($expectedLine['debit_amount']) ||
                $this->amountToCents($journalLine->credit_amount) !== $this->amountToCents($expectedLine['credit_amount']) ||
                $journalLine->memo !== $expectedLine['memo']
            ) {
                throw new RuntimeException('Existing JournalEntry draft lines conflict with candidate lines.');
            }
        }
    }

    private function assertNoProvenanceCollision(JournalCandidate $candidate): void
    {
        $collision = JournalEntry::where('property_id', $candidate->property_id)
            ->where('source_module', $this->sourceModuleForCandidate($candidate))
            ->where('source_type', $candidate->source_type)
            ->where('source_id', $candidate->source_id)
            ->where('posting_event', $candidate->posting_event)
            ->where(function ($query) use ($candidate) {
                $query->whereNull('journal_candidate_id')
                    ->orWhere('journal_candidate_id', '<>', $candidate->id);
            })
            ->lockForUpdate()
            ->first();

        if ($collision) {
            throw new RuntimeException('Conflicting JournalEntry provenance already exists for this journal candidate source.');
        }
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

    private function amountToCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
