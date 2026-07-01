<?php

namespace Modules\Finance\Banking\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Banking\Enums\BankPaymentReconciliationStatusEnum;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\Banking\Models\BankPaymentReconciliation;
use Modules\Finance\Banking\Models\ControlledBankAccount;
use Modules\Finance\Banking\Models\ControlledBankStatementLine;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use RuntimeException;
use Throwable;

class ManualBankReconciliationService
{
    public const PERMISSION = 'finance.banking.reconciliation.manual';
    public const CONTRACT = 'manual_bank_payment_reconciliation_v1';

    public function reconcilePostedBankPayment(
        string $postedJournalEntryId,
        string $controlledBankStatementLineId,
        ?User $actor
    ): BankPaymentReconciliation {
        return DB::transaction(function () use (
            $postedJournalEntryId,
            $controlledBankStatementLineId,
            $actor
        ): BankPaymentReconciliation {
            $actor = $this->resolveAuthorizedActor($actor);

            $journal = JournalEntry::with('lines')
                ->whereKey($postedJournalEntryId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostedBankSupplierPaymentJournal($journal);

            $execution = PaymentExecution::whereKey($journal->source_id)
                ->lockForUpdate()
                ->firstOrFail();

            $bankAccount = ControlledBankAccount::whereKey($execution->controlled_bank_account_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$bankAccount) {
                throw new DomainException('Active controlled bank account evidence is unavailable for reconciliation.');
            }

            $statementLine = ControlledBankStatementLine::whereKey($controlledBankStatementLineId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $journal->property_id);
            $this->assertBankReconciliationEvidence($journal, $execution, $bankAccount, $statementLine);

            $paymentAmount = $this->amountString($execution->source_amount);
            $statementAmount = $this->amountString($statementLine->amount);
            $difference = $this->amountString(($this->amountToCents($statementAmount) - $this->amountToCents($paymentAmount)) / 100);

            if ($this->amountToCents($difference) !== 0) {
                throw new DomainException('Manual bank reconciliation requires exact payment and statement amounts.');
            }

            $identityHash = $this->sourceIdentityHash($journal, $execution, $statementLine, $actor->id, $paymentAmount, $statementAmount, $difference);
            $snapshot = $this->sourceSnapshot($journal, $execution, $bankAccount, $statementLine, $actor->id, $paymentAmount, $statementAmount, $difference);

            $existing = BankPaymentReconciliation::where('payment_execution_id', $execution->id)
                ->orWhere('posted_journal_entry_id', $journal->id)
                ->orWhere('controlled_bank_statement_line_id', $statementLine->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingReconciliationMatches($existing, $journal, $execution, $statementLine, $actor->id, $identityHash);

                return $existing->fresh();
            }

            $reconciliation = new BankPaymentReconciliation([
                'property_id' => $journal->property_id,
                'controlled_bank_account_id' => $bankAccount->id,
                'controlled_bank_statement_line_id' => $statementLine->id,
                'payment_execution_id' => $execution->id,
                'posted_journal_entry_id' => $journal->id,
                'currency_code' => $execution->currency_code,
                'payment_amount' => $paymentAmount,
                'statement_amount' => $statementAmount,
                'difference_amount' => $difference,
                'status' => BankPaymentReconciliationStatusEnum::RECONCILED->value,
                'reconciled_by' => $actor->id,
                'reconciled_at' => now(),
                'source_identity_hash' => $identityHash,
                'source_snapshot' => $snapshot,
            ]);
            $reconciliation->created_by = $actor->id;
            $reconciliation->updated_by = $actor->id;
            $reconciliation->save();

            return $reconciliation->fresh();
        });
    }

    private function resolveAuthorizedActor(?User $actor): User
    {
        if (!$actor) {
            throw new AuthorizationException('Manual bank reconciliation requires an active actor.');
        }

        $freshActor = User::where('id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (!$freshActor) {
            throw new AuthorizationException('Manual bank reconciliation requires an active actor.');
        }

        try {
            $authorized = $freshActor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Manual bank reconciliation permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Manual bank reconciliation permission is required.');
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
            throw new AuthorizationException('Manual bank reconciliation requires active property access.');
        }
    }

    private function assertPostedBankSupplierPaymentJournal(JournalEntry $journal): void
    {
        if (
            $journal->status !== JournalStatusEnum::Posted ||
            $journal->source_module !== 'GeneralCashier' ||
            $journal->source_type !== 'PaymentExecution' ||
            $journal->source_id === null ||
            $journal->posting_event !== 'SupplierPaymentCashDisbursement' ||
            $journal->journal_candidate_id === null ||
            $journal->posting_date === null ||
            $journal->posted_by === null ||
            $journal->posted_at === null
        ) {
            throw new DomainException('Manual bank reconciliation requires posted BANK supplier payment JournalEntry evidence.');
        }
    }

    private function assertBankReconciliationEvidence(
        JournalEntry $journal,
        PaymentExecution $execution,
        ControlledBankAccount $bankAccount,
        ControlledBankStatementLine $statementLine
    ): void {
        if (
            $execution->id !== $journal->source_id ||
            $execution->property_id !== $journal->property_id ||
            $execution->controlled_bank_account_id === null ||
            $execution->controlled_bank_statement_line_id === null ||
            $execution->controlled_bank_statement_line_id !== $statementLine->id ||
            $execution->controlled_bank_account_id !== $bankAccount->id
        ) {
            throw new DomainException('PaymentExecution conflicts with posted BANK supplier payment JournalEntry.');
        }

        if (
            $bankAccount->property_id !== $journal->property_id ||
            $bankAccount->operational_gl_account_id !== $execution->operational_gl_account_id ||
            $bankAccount->currency_code !== $execution->currency_code
        ) {
            throw new DomainException('Controlled bank account conflicts with posted BANK payment evidence.');
        }

        if (
            $statementLine->controlled_bank_account_id !== $bankAccount->id ||
            $statementLine->property_id !== $journal->property_id ||
            $statementLine->currency_code !== $execution->currency_code ||
            $statementLine->direction !== ControlledBankStatementLineDirectionEnum::OUTFLOW
        ) {
            throw new DomainException('Bank statement-line evidence conflicts with posted BANK payment evidence.');
        }

        $bankCreditLines = $journal->lines
            ->filter(fn ($line): bool => $line->account_id === $bankAccount->operational_gl_account_id
                && $this->amountToCents($line->credit_amount) === $this->amountToCents($execution->source_amount)
                && $this->amountToCents($line->debit_amount) === 0)
            ->values();

        if ($bankCreditLines->count() !== 1) {
            throw new DomainException('Posted BANK supplier payment JournalEntry must contain one bank credit line.');
        }
    }

    private function assertExistingReconciliationMatches(
        BankPaymentReconciliation $existing,
        JournalEntry $journal,
        PaymentExecution $execution,
        ControlledBankStatementLine $statementLine,
        string $actorId,
        string $identityHash
    ): void {
        if (
            $existing->property_id === $journal->property_id &&
            $existing->controlled_bank_account_id === $execution->controlled_bank_account_id &&
            $existing->controlled_bank_statement_line_id === $statementLine->id &&
            $existing->payment_execution_id === $execution->id &&
            $existing->posted_journal_entry_id === $journal->id &&
            $existing->status === BankPaymentReconciliationStatusEnum::RECONCILED &&
            $existing->reconciled_by === $actorId &&
            $existing->reconciled_at !== null &&
            $existing->source_identity_hash === $identityHash
        ) {
            return;
        }

        throw new RuntimeException('Conflicting manual bank reconciliation evidence already exists.');
    }

    private function sourceIdentityHash(
        JournalEntry $journal,
        PaymentExecution $execution,
        ControlledBankStatementLine $statementLine,
        string $actorId,
        string $paymentAmount,
        string $statementAmount,
        string $difference
    ): string {
        return hash('sha256', implode('|', [
            self::CONTRACT,
            $journal->id,
            $execution->id,
            $execution->controlled_bank_account_id,
            $statementLine->id,
            $paymentAmount,
            $statementAmount,
            $difference,
            $actorId,
        ]));
    }

    private function sourceSnapshot(
        JournalEntry $journal,
        PaymentExecution $execution,
        ControlledBankAccount $bankAccount,
        ControlledBankStatementLine $statementLine,
        string $actorId,
        string $paymentAmount,
        string $statementAmount,
        string $difference
    ): array {
        return [
            'contract' => self::CONTRACT,
            'posted_journal_entry_id' => $journal->id,
            'payment_execution_id' => $execution->id,
            'controlled_bank_account_id' => $bankAccount->id,
            'controlled_bank_statement_line_id' => $statementLine->id,
            'currency_code' => $execution->currency_code,
            'payment_amount' => $paymentAmount,
            'statement_amount' => $statementAmount,
            'difference_amount' => $difference,
            'reconciled_by' => $actorId,
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
