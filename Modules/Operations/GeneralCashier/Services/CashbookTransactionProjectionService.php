<?php

namespace Modules\Operations\GeneralCashier\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Operations\GeneralCashier\Enums\CashbookTransactionDirectionEnum;
use Modules\Operations\GeneralCashier\Enums\CashierPaymentInstrumentTypeEnum;
use Modules\Operations\GeneralCashier\Models\CashbookTransaction;
use Modules\Operations\GeneralCashier\Models\CashierPaymentInstrument;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;

class CashbookTransactionProjectionService
{
    public const CONTRACT = 'cashbook_transaction_from_posted_cash_supplier_payment_v1';
    public const SOURCE_MODULE = 'GeneralCashier';
    public const SOURCE_TYPE = 'PaymentExecution';
    public const POSTING_EVENT = 'SupplierPaymentCashDisbursement';

    public function projectPostedCashSupplierPayment(JournalEntry $journal, string $actorId): ?CashbookTransaction
    {
        if (!$this->isSupportedPostedCashPaymentJournal($journal)) {
            return null;
        }

        return DB::transaction(function () use ($journal, $actorId): ?CashbookTransaction {
            $journal = JournalEntry::with('lines')
                ->whereKey($journal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$this->isSupportedPostedCashPaymentJournal($journal)) {
                return null;
            }

            $this->assertPostedJournalEvidence($journal);

            $execution = PaymentExecution::whereKey($journal->source_id)
                ->lockForUpdate()
                ->firstOrFail();

            $instrument = CashierPaymentInstrument::whereKey($execution->cashier_payment_instrument_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPaymentExecutionEvidence($journal, $execution, $instrument);

            $amount = $this->amountString($execution->source_amount);
            $postedBusinessDate = $journal->transaction_date->toDateString();
            $identityHash = $this->sourceIdentityHash($journal, $execution, $amount, $postedBusinessDate);
            $snapshot = $this->sourceSnapshot($journal, $execution, $instrument, $amount, $postedBusinessDate);

            $existing = CashbookTransaction::where('journal_entry_id', $journal->id)
                ->orWhere('payment_execution_id', $execution->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingTransactionMatches(
                    $existing,
                    $journal,
                    $execution,
                    $amount,
                    $postedBusinessDate,
                    $identityHash
                );

                return $existing->fresh();
            }

            $transaction = new CashbookTransaction([
                'property_id' => $execution->property_id,
                'operational_gl_account_id' => $execution->operational_gl_account_id,
                'currency_code' => $execution->currency_code,
                'amount' => $amount,
                'direction' => CashbookTransactionDirectionEnum::OUTFLOW->value,
                'posted_business_date' => $postedBusinessDate,
                'journal_entry_id' => $journal->id,
                'payment_execution_id' => $execution->id,
                'source_module' => 'GeneralLedger',
                'source_type' => 'JournalEntry',
                'source_id' => $journal->id,
                'source_event' => self::POSTING_EVENT,
                'source_identity_hash' => $identityHash,
                'source_snapshot' => $snapshot,
                'projected_by' => $actorId,
                'projected_at' => now(),
            ]);
            $transaction->created_by = $actorId;
            $transaction->updated_by = $actorId;
            $transaction->save();

            return $transaction->fresh();
        });
    }

    private function isSupportedPostedCashPaymentJournal(JournalEntry $journal): bool
    {
        return $journal->source_module === self::SOURCE_MODULE
            && $journal->source_type === self::SOURCE_TYPE
            && $journal->posting_event === self::POSTING_EVENT
            && $journal->source_id !== null;
    }

    private function assertPostedJournalEvidence(JournalEntry $journal): void
    {
        if (
            $journal->status !== JournalStatusEnum::Posted ||
            $journal->journal_candidate_id === null ||
            $journal->posting_date === null ||
            $journal->posted_by === null ||
            $journal->posted_at === null
        ) {
            throw new DomainException('Cashbook projection requires posted supplier payment JournalEntry evidence.');
        }

        if ($journal->lines->isEmpty()) {
            throw new DomainException('Cashbook projection requires JournalEntry line evidence.');
        }
    }

    private function assertPaymentExecutionEvidence(
        JournalEntry $journal,
        PaymentExecution $execution,
        CashierPaymentInstrument $instrument
    ): void {
        if (
            $execution->property_id !== $journal->property_id ||
            $execution->id !== $journal->source_id ||
            $execution->executed_by === null ||
            $execution->executed_at === null
        ) {
            throw new DomainException('Payment Execution conflicts with posted supplier payment JournalEntry.');
        }

        if (
            $instrument->property_id !== $execution->property_id ||
            $instrument->id !== $execution->cashier_payment_instrument_id ||
            $instrument->type !== CashierPaymentInstrumentTypeEnum::CASH ||
            $instrument->operational_gl_account_id !== $execution->operational_gl_account_id
        ) {
            throw new DomainException('Cashbook projection requires source-proven CASH instrument evidence.');
        }

        $amount = $this->amountString($execution->source_amount);
        if ($this->amountToCents($amount) <= 0) {
            throw new DomainException('Cashbook projection requires a positive payment amount.');
        }

        $cashCreditLines = $journal->lines
            ->filter(fn ($line): bool => $line->property_id === $execution->property_id
                && $line->account_id === $execution->operational_gl_account_id
                && $this->amountToCents($line->credit_amount) > 0
                && $this->amountToCents($line->debit_amount) === 0)
            ->values();

        if ($cashCreditLines->count() !== 1) {
            throw new DomainException('Posted supplier payment JournalEntry must contain exactly one cash credit line.');
        }

        if ($this->amountString($cashCreditLines->first()->credit_amount) !== $amount) {
            throw new DomainException('Posted supplier payment cash credit amount conflicts with Payment Execution.');
        }
    }

    private function assertExistingTransactionMatches(
        CashbookTransaction $existing,
        JournalEntry $journal,
        PaymentExecution $execution,
        string $amount,
        string $postedBusinessDate,
        string $identityHash
    ): void {
        if (
            $existing->property_id === $execution->property_id &&
            $existing->operational_gl_account_id === $execution->operational_gl_account_id &&
            $existing->currency_code === $execution->currency_code &&
            $this->amountString($existing->amount) === $amount &&
            $existing->direction === CashbookTransactionDirectionEnum::OUTFLOW &&
            $existing->posted_business_date->toDateString() === $postedBusinessDate &&
            $existing->journal_entry_id === $journal->id &&
            $existing->payment_execution_id === $execution->id &&
            $existing->source_module === 'GeneralLedger' &&
            $existing->source_type === 'JournalEntry' &&
            $existing->source_id === $journal->id &&
            $existing->source_event === self::POSTING_EVENT &&
            $existing->source_identity_hash === $identityHash
        ) {
            return;
        }

        throw new DomainException('Conflicting CashbookTransaction evidence already exists.');
    }

    private function sourceIdentityHash(
        JournalEntry $journal,
        PaymentExecution $execution,
        string $amount,
        string $postedBusinessDate
    ): string {
        return hash('sha256', implode('|', [
            self::CONTRACT,
            $journal->property_id,
            $journal->id,
            $journal->journal_candidate_id,
            $journal->posting_event,
            $execution->id,
            $execution->payment_proposal_item_id,
            $execution->operational_gl_account_id,
            $execution->currency_code,
            $amount,
            $postedBusinessDate,
        ]));
    }

    private function sourceSnapshot(
        JournalEntry $journal,
        PaymentExecution $execution,
        CashierPaymentInstrument $instrument,
        string $amount,
        string $postedBusinessDate
    ): array {
        return [
            'contract' => self::CONTRACT,
            'direction' => CashbookTransactionDirectionEnum::OUTFLOW->value,
            'amount' => $amount,
            'currency_code' => $execution->currency_code,
            'posted_business_date' => $postedBusinessDate,
            'journal_entry' => [
                'id' => $journal->id,
                'property_id' => $journal->property_id,
                'journal_candidate_id' => $journal->journal_candidate_id,
                'posting_event' => $journal->posting_event,
                'transaction_date' => $journal->transaction_date->toDateString(),
                'posting_date' => $journal->posting_date->toDateString(),
                'posted_by' => $journal->posted_by,
                'posted_at' => $journal->posted_at?->toISOString(),
            ],
            'payment_execution' => [
                'id' => $execution->id,
                'payment_proposal_id' => $execution->payment_proposal_id,
                'payment_proposal_item_id' => $execution->payment_proposal_item_id,
                'source_journal_entry_id' => $execution->source_journal_entry_id,
                'source_journal_candidate_id' => $execution->source_journal_candidate_id,
                'supplier_invoice_id' => $execution->supplier_invoice_id,
                'vendor_id' => $execution->vendor_id,
                'cashier_session_id' => $execution->cashier_session_id,
                'cashier_payment_instrument_id' => $execution->cashier_payment_instrument_id,
                'operational_gl_account_id' => $execution->operational_gl_account_id,
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
