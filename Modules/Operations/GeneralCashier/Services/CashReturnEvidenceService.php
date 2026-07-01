<?php

namespace Modules\Operations\GeneralCashier\Services;

use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierPaymentInstrumentTypeEnum;
use Modules\Operations\GeneralCashier\Models\CashReturnEvidence;
use Modules\Operations\GeneralCashier\Models\CashierPaymentInstrument;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Throwable;

class CashReturnEvidenceService
{
    public const PERMISSION = 'finance.general-cashier.cash-return.record';
    public const CONTRACT = 'cash_return_evidence_from_posted_cash_supplier_payment_v1';
    public const POSTING_EVENT = 'SupplierPaymentCashDisbursement';

    public function recordCashReturn(
        string $postedPaymentJournalEntryId,
        string $sourceReference,
        string $observedReturnDate,
        ?User $actor
    ): CashReturnEvidence {
        return DB::transaction(function () use (
            $postedPaymentJournalEntryId,
            $sourceReference,
            $observedReturnDate,
            $actor
        ): CashReturnEvidence {
            $actor = $this->resolveAuthorizedActor($actor);
            $sourceReference = trim($sourceReference);

            if ($sourceReference === '') {
                throw new DomainException('Cash Return source reference is required.');
            }

            $journal = JournalEntry::with('lines')
                ->whereKey($postedPaymentJournalEntryId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostedCashSupplierPaymentJournal($journal);

            $execution = PaymentExecution::whereKey($journal->source_id)
                ->lockForUpdate()
                ->firstOrFail();

            $instrument = CashierPaymentInstrument::whereKey($execution->cashier_payment_instrument_id)
                ->lockForUpdate()
                ->firstOrFail();

            $account = Account::whereKey($execution->operational_gl_account_id)
                ->where('is_active', true)
                ->where('is_cash_equivalent', true)
                ->lockForUpdate()
                ->first();

            if (!$account) {
                throw new DomainException('Active cash control account is unavailable for Cash Return evidence.');
            }

            $this->assertActorCanAccessProperty($actor, $execution->property_id);
            $this->assertExecutionInstrumentAndAccountEvidence($journal, $execution, $instrument, $account);

            $amount = $this->amountString($execution->source_amount);
            $returnDate = Carbon::parse($observedReturnDate)->toDateString();
            $identityHash = $this->sourceIdentityHash($journal, $execution, $sourceReference, $returnDate, $actor->id, $amount);
            $snapshot = $this->sourceSnapshot($journal, $execution, $instrument, $sourceReference, $returnDate, $actor->id, $amount);

            $existing = CashReturnEvidence::where('payment_execution_id', $execution->id)
                ->orWhere('posted_journal_entry_id', $journal->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingReturnMatches($existing, $journal, $execution, $sourceReference, $returnDate, $actor->id, $identityHash, $amount);

                return $existing->fresh();
            }

            $return = new CashReturnEvidence([
                'payment_execution_id' => $execution->id,
                'posted_journal_entry_id' => $journal->id,
                'property_id' => $execution->property_id,
                'vendor_id' => $execution->vendor_id,
                'operational_gl_account_id' => $execution->operational_gl_account_id,
                'currency_code' => $execution->currency_code,
                'return_amount' => $amount,
                'observed_return_date' => $returnDate,
                'source_reference' => $sourceReference,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
                'source_identity_hash' => $identityHash,
                'source_snapshot' => $snapshot,
            ]);
            $return->created_by = $actor->id;
            $return->updated_by = $actor->id;
            $return->save();

            return $return->fresh();
        });
    }

    private function resolveAuthorizedActor(?User $actor): User
    {
        if (!$actor) {
            throw new AuthorizationException('Cash Return evidence requires an active actor.');
        }

        $freshActor = User::where('id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (!$freshActor) {
            throw new AuthorizationException('Cash Return evidence requires an active actor.');
        }

        try {
            $authorized = $freshActor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Cash Return evidence permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Cash Return evidence permission is required.');
        }

        return $freshActor;
    }

    private function assertActorCanAccessProperty(User $actor, string $propertyId): void
    {
        $hasPropertyAccess = $actor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('Cash Return evidence requires active property access.');
        }
    }

    private function assertPostedCashSupplierPaymentJournal(JournalEntry $journal): void
    {
        if (
            $journal->status !== JournalStatusEnum::Posted ||
            $journal->source_module !== 'GeneralCashier' ||
            $journal->source_type !== 'PaymentExecution' ||
            $journal->source_id === null ||
            $journal->posting_event !== self::POSTING_EVENT ||
            $journal->journal_candidate_id === null ||
            $journal->posted_by === null ||
            $journal->posted_at === null ||
            $journal->posting_date === null
        ) {
            throw new DomainException('Cash Return requires posted CASH supplier payment JournalEntry evidence.');
        }

        if ($journal->lines->isEmpty()) {
            throw new DomainException('Cash Return requires posted JournalEntry line evidence.');
        }
    }

    private function assertExecutionInstrumentAndAccountEvidence(
        JournalEntry $journal,
        PaymentExecution $execution,
        CashierPaymentInstrument $instrument,
        Account $account
    ): void {
        if (
            $execution->id !== $journal->source_id ||
            $execution->property_id !== $journal->property_id ||
            $execution->executed_by === null ||
            $execution->executed_at === null
        ) {
            throw new DomainException('Cash Return PaymentExecution conflicts with posted JournalEntry evidence.');
        }

        if (
            $instrument->id !== $execution->cashier_payment_instrument_id ||
            $instrument->property_id !== $execution->property_id ||
            $instrument->type !== CashierPaymentInstrumentTypeEnum::CASH ||
            !$instrument->is_active ||
            $instrument->operational_gl_account_id !== $execution->operational_gl_account_id
        ) {
            throw new DomainException('Cash Return requires active CASH instrument evidence.');
        }

        if (
            $account->id !== $execution->operational_gl_account_id ||
            $account->property_id !== $execution->property_id ||
            !$account->is_active
        ) {
            throw new DomainException('Cash Return cash control account conflicts with PaymentExecution.');
        }

        $amount = $this->amountString($execution->source_amount);
        if ($this->amountToCents($amount) <= 0) {
            throw new DomainException('Cash Return amount must be positive.');
        }

        $cashCreditLines = $journal->lines
            ->filter(fn ($line): bool => $line->property_id === $execution->property_id
                && $line->account_id === $execution->operational_gl_account_id
                && $this->amountToCents($line->credit_amount) > 0
                && $this->amountToCents($line->debit_amount) === 0)
            ->values();

        if ($cashCreditLines->count() !== 1) {
            throw new DomainException('Cash Return requires exactly one posted cash credit line.');
        }

        if ($this->amountString($cashCreditLines->first()->credit_amount) !== $amount) {
            throw new DomainException('Cash Return amount conflicts with posted cash payment evidence.');
        }
    }

    private function assertExistingReturnMatches(
        CashReturnEvidence $existing,
        JournalEntry $journal,
        PaymentExecution $execution,
        string $sourceReference,
        string $returnDate,
        string $actorId,
        string $identityHash,
        string $amount
    ): void {
        if (
            $existing->payment_execution_id === $execution->id &&
            $existing->posted_journal_entry_id === $journal->id &&
            $existing->property_id === $execution->property_id &&
            $existing->vendor_id === $execution->vendor_id &&
            $existing->operational_gl_account_id === $execution->operational_gl_account_id &&
            $existing->currency_code === $execution->currency_code &&
            $this->amountString($existing->return_amount) === $amount &&
            $existing->observed_return_date->toDateString() === $returnDate &&
            $existing->source_reference === $sourceReference &&
            $existing->recorded_by === $actorId &&
            $existing->recorded_at !== null &&
            $existing->source_identity_hash === $identityHash
        ) {
            return;
        }

        throw new DomainException('Conflicting Cash Return evidence already exists.');
    }

    private function sourceIdentityHash(
        JournalEntry $journal,
        PaymentExecution $execution,
        string $sourceReference,
        string $returnDate,
        string $actorId,
        string $amount
    ): string {
        return hash('sha256', implode('|', [
            self::CONTRACT,
            $journal->id,
            $journal->journal_candidate_id,
            $execution->id,
            $execution->property_id,
            $execution->vendor_id,
            $execution->operational_gl_account_id,
            $execution->currency_code,
            $amount,
            $returnDate,
            $sourceReference,
            $actorId,
        ]));
    }

    private function sourceSnapshot(
        JournalEntry $journal,
        PaymentExecution $execution,
        CashierPaymentInstrument $instrument,
        string $sourceReference,
        string $returnDate,
        string $actorId,
        string $amount
    ): array {
        return [
            'contract' => self::CONTRACT,
            'source_reference' => $sourceReference,
            'observed_return_date' => $returnDate,
            'return_amount' => $amount,
            'recorded_by' => $actorId,
            'journal_entry' => [
                'id' => $journal->id,
                'property_id' => $journal->property_id,
                'journal_candidate_id' => $journal->journal_candidate_id,
                'posting_event' => $journal->posting_event,
                'posting_date' => $journal->posting_date->toDateString(),
                'posted_by' => $journal->posted_by,
                'posted_at' => $journal->posted_at?->toISOString(),
            ],
            'payment_execution' => [
                'id' => $execution->id,
                'vendor_id' => $execution->vendor_id,
                'payment_proposal_id' => $execution->payment_proposal_id,
                'payment_proposal_item_id' => $execution->payment_proposal_item_id,
                'source_journal_entry_id' => $execution->source_journal_entry_id,
                'source_journal_candidate_id' => $execution->source_journal_candidate_id,
                'supplier_invoice_id' => $execution->supplier_invoice_id,
                'operational_gl_account_id' => $execution->operational_gl_account_id,
                'currency_code' => $execution->currency_code,
                'source_amount' => $amount,
                'executed_by' => $execution->executed_by,
                'executed_at' => $execution->executed_at?->toISOString(),
            ],
            'cashier_payment_instrument' => [
                'id' => $instrument->id,
                'type' => $instrument->type->value,
                'operational_gl_account_id' => $instrument->operational_gl_account_id,
            ],
        ];
    }

    private function amountToCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function amountString(mixed $amount): string
    {
        return number_format(((float) $amount), 2, '.', '');
    }
}
