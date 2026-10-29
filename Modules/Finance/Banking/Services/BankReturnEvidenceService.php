<?php

namespace Modules\Finance\Banking\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\Banking\Models\BankReturnEvidence;
use Modules\Finance\Banking\Models\ControlledBankAccount;
use Modules\Finance\Banking\Models\ControlledBankStatementLine;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;

class BankReturnEvidenceService
{
    public const PERMISSION = 'finance.banking.bank-return.record';

    public const CONTRACT = 'bank_return_evidence_from_external_inflow_v1';

    public const REASON_CODES = ['BANK_RETURN', 'PAYMENT_REJECTED', 'BENEFICIARY_REJECTED'];

    public function record(
        string $postedPaymentJournalEntryId,
        string $returnStatementLineId,
        string $reasonCode,
        string $idempotencyKey,
        ?User $actor
    ): BankReturnEvidence {
        return DB::transaction(function () use ($postedPaymentJournalEntryId, $returnStatementLineId, $reasonCode, $idempotencyKey, $actor): BankReturnEvidence {
            $actor = $this->authorizedActor($actor);
            $reasonCode = strtoupper(trim($reasonCode));
            $idempotencyKey = trim($idempotencyKey);
            if (! in_array($reasonCode, self::REASON_CODES, true)) {
                throw new DomainException('Bank return reason code is unsupported.');
            }
            if ($idempotencyKey === '' || strlen($idempotencyKey) > 96) {
                throw new DomainException('Bank return idempotency key is invalid.');
            }

            $journal = JournalEntry::with('lines')->whereKey($postedPaymentJournalEntryId)->lockForUpdate()->firstOrFail();
            if ($journal->status !== JournalStatusEnum::Posted
                || $journal->source_module !== 'GeneralCashier'
                || $journal->source_type !== 'PaymentExecution'
                || $journal->posting_event !== 'SupplierPaymentCashDisbursement') {
                throw new DomainException('Bank return requires posted supplier-payment journal evidence.');
            }

            $execution = PaymentExecution::withoutGlobalScope('property')->whereKey($journal->source_id)->lockForUpdate()->firstOrFail();
            if (! $execution->controlled_bank_account_id || ! $execution->controlled_bank_statement_line_id
                || $execution->property_id !== $journal->property_id) {
                throw new DomainException('Bank return requires a confirmed bank PaymentExecution.');
            }
            $this->assertPropertyAccess($actor, $execution->property_id);

            $account = ControlledBankAccount::withoutGlobalScope('property')->whereKey($execution->controlled_bank_account_id)
                ->where('property_id', $execution->property_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $originalLine = ControlledBankStatementLine::withoutGlobalScope('property')->whereKey($execution->controlled_bank_statement_line_id)->lockForUpdate()->firstOrFail();
            $returnLine = ControlledBankStatementLine::withoutGlobalScope('property')->whereKey($returnStatementLineId)->lockForUpdate()->firstOrFail();

            if ($returnLine->id === $originalLine->id
                || $returnLine->controlled_bank_account_id !== $account->id
                || $returnLine->property_id !== $execution->property_id
                || $returnLine->direction !== ControlledBankStatementLineDirectionEnum::INFLOW
                || $returnLine->currency_code !== $execution->currency_code
                || $this->amount($returnLine->amount) !== $this->amount($execution->source_amount)
                || ($returnLine->vendor_reference !== null && $returnLine->vendor_reference !== $execution->vendor_id)
                || $returnLine->external_reference === ''
                || $returnLine->source_reference === '') {
                throw new DomainException('Independent bank-return statement evidence conflicts with the original payment.');
            }

            $snapshot = [
                'contract' => self::CONTRACT,
                'payment_execution_id' => $execution->id,
                'posted_journal_entry_id' => $journal->id,
                'controlled_bank_account_id' => $account->id,
                'original_statement_line_id' => $originalLine->id,
                'return_statement_line_id' => $returnLine->id,
                'return_external_reference' => $returnLine->external_reference,
                'return_source_reference' => $returnLine->source_reference,
                'return_vendor_reference' => $returnLine->vendor_reference,
                'reason_code' => $reasonCode,
                'recorded_by' => $actor->id,
            ];
            $hash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $existing = BankReturnEvidence::withoutGlobalScope('property')
                ->where(fn ($query) => $query->where('payment_execution_id', $execution->id)
                    ->orWhere('return_statement_line_id', $returnLine->id)
                    ->orWhere(fn ($inner) => $inner->where('property_id', $execution->property_id)->where('idempotency_key', $idempotencyKey)))
                ->lockForUpdate()->first();
            if ($existing) {
                if ($existing->source_identity_hash === $hash) {
                    return $existing->fresh();
                }
                throw new DomainException('Conflicting bank-return evidence already exists.');
            }

            return BankReturnEvidence::create([
                'payment_execution_id' => $execution->id,
                'posted_journal_entry_id' => $journal->id,
                'controlled_bank_account_id' => $account->id,
                'return_statement_line_id' => $returnLine->id,
                'property_id' => $execution->property_id,
                'vendor_id' => $execution->vendor_id,
                'operational_gl_account_id' => $execution->operational_gl_account_id,
                'currency_code' => $execution->currency_code,
                'return_amount' => $this->amount($execution->source_amount),
                'observed_return_date' => $returnLine->statement_date,
                'reason_code' => $reasonCode,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'source_identity_hash' => $hash,
                'source_snapshot' => $snapshot,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->fresh();
        });
    }

    private function authorizedActor(?User $actor): User
    {
        $actor = $actor ? User::whereKey($actor->id)->where('is_active', true)->first() : null;
        if (! $actor || ! $actor->can(self::PERMISSION)) {
            throw new AuthorizationException('Bank return evidence permission is required.');
        }

        return $actor;
    }

    private function assertPropertyAccess(User $actor, string $propertyId): void
    {
        if (! $actor->properties()->where('properties.id', $propertyId)->wherePivot('status', 'active')->exists()) {
            throw new AuthorizationException('Bank return evidence requires active property access.');
        }
    }

    private function amount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
