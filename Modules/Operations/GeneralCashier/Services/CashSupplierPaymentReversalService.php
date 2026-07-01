<?php

namespace Modules\Operations\GeneralCashier\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\CashReturnEvidence;
use Modules\Operations\GeneralCashier\Models\CashSupplierPaymentReversalExecution;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Throwable;

class CashSupplierPaymentReversalService
{
    public const PERMISSION = 'finance.general-cashier.cash-payment-reversal.create';
    public const CONTRACT = 'cash_supplier_payment_reversal_execution_from_return_evidence_v1';

    public function recordReversalExecution(
        string $cashReturnEvidenceId,
        ?User $actor
    ): CashSupplierPaymentReversalExecution {
        return DB::transaction(function () use ($cashReturnEvidenceId, $actor): CashSupplierPaymentReversalExecution {
            $actor = $this->resolveAuthorizedActor($actor);

            $returnEvidence = CashReturnEvidence::whereKey($cashReturnEvidenceId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $returnEvidence->property_id);

            $execution = PaymentExecution::whereKey($returnEvidence->payment_execution_id)
                ->lockForUpdate()
                ->firstOrFail();

            $journal = JournalEntry::with('lines')
                ->whereKey($returnEvidence->posted_journal_entry_id)
                ->lockForUpdate()
                ->firstOrFail();

            $account = Account::whereKey($returnEvidence->operational_gl_account_id)
                ->where('is_active', true)
                ->where('is_cash_equivalent', true)
                ->lockForUpdate()
                ->first();

            if (!$account) {
                throw new DomainException('Active cash control account is unavailable for supplier payment reversal.');
            }

            $this->assertReturnEvidenceMatchesOriginalPayment($returnEvidence, $execution, $journal, $account);

            $amount = $this->amountString($returnEvidence->return_amount);
            $identityHash = $this->sourceIdentityHash($returnEvidence, $execution, $journal, $actor->id, $amount);
            $snapshot = $this->sourceSnapshot($returnEvidence, $execution, $journal, $actor->id, $amount);

            $existing = CashSupplierPaymentReversalExecution::where('cash_return_evidence_id', $returnEvidence->id)
                ->orWhere('original_payment_execution_id', $execution->id)
                ->orWhere('original_posted_journal_entry_id', $journal->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingReversalMatches($existing, $returnEvidence, $execution, $journal, $actor->id, $identityHash, $amount);

                return $existing->fresh();
            }

            $reversal = new CashSupplierPaymentReversalExecution([
                'cash_return_evidence_id' => $returnEvidence->id,
                'original_payment_execution_id' => $execution->id,
                'original_posted_journal_entry_id' => $journal->id,
                'property_id' => $returnEvidence->property_id,
                'vendor_id' => $returnEvidence->vendor_id,
                'operational_gl_account_id' => $returnEvidence->operational_gl_account_id,
                'currency_code' => $returnEvidence->currency_code,
                'reversal_amount' => $amount,
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
                'source_identity_hash' => $identityHash,
                'source_snapshot' => $snapshot,
            ]);
            $reversal->created_by = $actor->id;
            $reversal->updated_by = $actor->id;
            $reversal->save();

            return $reversal->fresh();
        });
    }

    private function resolveAuthorizedActor(?User $actor): User
    {
        if (!$actor) {
            throw new AuthorizationException('Cash supplier payment reversal requires an active actor.');
        }

        $freshActor = User::where('id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (!$freshActor) {
            throw new AuthorizationException('Cash supplier payment reversal requires an active actor.');
        }

        try {
            $authorized = $freshActor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Cash supplier payment reversal permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Cash supplier payment reversal permission is required.');
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
            throw new AuthorizationException('Cash supplier payment reversal requires active property access.');
        }
    }

    private function assertReturnEvidenceMatchesOriginalPayment(
        CashReturnEvidence $returnEvidence,
        PaymentExecution $execution,
        JournalEntry $journal,
        Account $account
    ): void {
        if (
            $returnEvidence->payment_execution_id !== $execution->id ||
            $returnEvidence->posted_journal_entry_id !== $journal->id ||
            $returnEvidence->property_id !== $execution->property_id ||
            $returnEvidence->vendor_id !== $execution->vendor_id ||
            $returnEvidence->operational_gl_account_id !== $execution->operational_gl_account_id ||
            $returnEvidence->currency_code !== $execution->currency_code ||
            $this->amountString($returnEvidence->return_amount) !== $this->amountString($execution->source_amount)
        ) {
            throw new DomainException('Cash Return evidence conflicts with original PaymentExecution.');
        }

        if (
            $journal->status !== JournalStatusEnum::Posted ||
            $journal->property_id !== $execution->property_id ||
            $journal->source_module !== 'GeneralCashier' ||
            $journal->source_type !== 'PaymentExecution' ||
            $journal->source_id !== $execution->id ||
            $journal->posting_event !== CashReturnEvidenceService::POSTING_EVENT ||
            $journal->journal_candidate_id === null ||
            $journal->posting_date === null ||
            $journal->posted_by === null ||
            $journal->posted_at === null
        ) {
            throw new DomainException('Cash supplier payment reversal requires posted original CASH payment JournalEntry evidence.');
        }

        if (
            $account->id !== $returnEvidence->operational_gl_account_id ||
            $account->property_id !== $returnEvidence->property_id ||
            !$account->is_active
        ) {
            throw new DomainException('Cash supplier payment reversal cash account evidence is invalid.');
        }

        if ($journal->lines->isEmpty()) {
            throw new DomainException('Cash supplier payment reversal requires original JournalEntry line evidence.');
        }

        $amount = $this->amountString($returnEvidence->return_amount);
        $cashCreditLines = $journal->lines
            ->filter(fn ($line): bool => $line->property_id === $returnEvidence->property_id
                && $line->account_id === $returnEvidence->operational_gl_account_id
                && $this->amountToCents($line->credit_amount) === $this->amountToCents($amount)
                && $this->amountToCents($line->debit_amount) === 0)
            ->values();

        if ($cashCreditLines->count() !== 1) {
            throw new DomainException('Cash supplier payment reversal requires one original cash credit line.');
        }
    }

    private function assertExistingReversalMatches(
        CashSupplierPaymentReversalExecution $existing,
        CashReturnEvidence $returnEvidence,
        PaymentExecution $execution,
        JournalEntry $journal,
        string $actorId,
        string $identityHash,
        string $amount
    ): void {
        if (
            $existing->cash_return_evidence_id === $returnEvidence->id &&
            $existing->original_payment_execution_id === $execution->id &&
            $existing->original_posted_journal_entry_id === $journal->id &&
            $existing->property_id === $returnEvidence->property_id &&
            $existing->vendor_id === $returnEvidence->vendor_id &&
            $existing->operational_gl_account_id === $returnEvidence->operational_gl_account_id &&
            $existing->currency_code === $returnEvidence->currency_code &&
            $this->amountString($existing->reversal_amount) === $amount &&
            $existing->reversed_by === $actorId &&
            $existing->reversed_at !== null &&
            $existing->source_identity_hash === $identityHash
        ) {
            return;
        }

        throw new DomainException('Conflicting cash supplier payment reversal evidence already exists.');
    }

    private function sourceIdentityHash(
        CashReturnEvidence $returnEvidence,
        PaymentExecution $execution,
        JournalEntry $journal,
        string $actorId,
        string $amount
    ): string {
        return hash('sha256', implode('|', [
            self::CONTRACT,
            $returnEvidence->id,
            $execution->id,
            $journal->id,
            $returnEvidence->property_id,
            $returnEvidence->vendor_id,
            $returnEvidence->operational_gl_account_id,
            $returnEvidence->currency_code,
            $amount,
            $actorId,
        ]));
    }

    private function sourceSnapshot(
        CashReturnEvidence $returnEvidence,
        PaymentExecution $execution,
        JournalEntry $journal,
        string $actorId,
        string $amount
    ): array {
        return [
            'contract' => self::CONTRACT,
            'cash_return_evidence_id' => $returnEvidence->id,
            'original_payment_execution_id' => $execution->id,
            'original_posted_journal_entry_id' => $journal->id,
            'property_id' => $returnEvidence->property_id,
            'vendor_id' => $returnEvidence->vendor_id,
            'operational_gl_account_id' => $returnEvidence->operational_gl_account_id,
            'currency_code' => $returnEvidence->currency_code,
            'reversal_amount' => $amount,
            'reversed_by' => $actorId,
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
