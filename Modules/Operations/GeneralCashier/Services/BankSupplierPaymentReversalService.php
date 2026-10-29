<?php

namespace Modules\Operations\GeneralCashier\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Banking\Models\BankReturnEvidence;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\BankSupplierPaymentReversalExecution;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;

class BankSupplierPaymentReversalService
{
    public const PERMISSION = 'finance.general-cashier.bank-payment-reversal.create';

    public const CONTRACT = 'bank_supplier_payment_reversal_execution_v1';

    public function record(string $bankReturnEvidenceId, ?User $actor): BankSupplierPaymentReversalExecution
    {
        return DB::transaction(function () use ($bankReturnEvidenceId, $actor): BankSupplierPaymentReversalExecution {
            $actor = $actor ? User::whereKey($actor->id)->where('is_active', true)->first() : null;
            if (! $actor || ! $actor->can(self::PERMISSION)) {
                throw new AuthorizationException('Bank supplier-payment reversal permission is required.');
            }

            $return = BankReturnEvidence::withoutGlobalScope('property')->whereKey($bankReturnEvidenceId)->lockForUpdate()->firstOrFail();
            if (! $actor->properties()->where('properties.id', $return->property_id)->wherePivot('status', 'active')->exists()) {
                throw new AuthorizationException('Bank supplier-payment reversal requires active property access.');
            }
            $execution = PaymentExecution::withoutGlobalScope('property')->whereKey($return->payment_execution_id)->lockForUpdate()->firstOrFail();
            $journal = JournalEntry::with('lines')->withoutGlobalScope('property')->whereKey($return->posted_journal_entry_id)->lockForUpdate()->firstOrFail();
            if ($journal->status !== JournalStatusEnum::Posted
                || $journal->source_id !== $execution->id
                || $execution->controlled_bank_account_id !== $return->controlled_bank_account_id
                || $execution->property_id !== $return->property_id
                || $execution->vendor_id !== $return->vendor_id
                || $execution->currency_code !== $return->currency_code
                || $this->amount($execution->source_amount) !== $this->amount($return->return_amount)) {
                throw new DomainException('Bank Return evidence conflicts with the original posted payment.');
            }

            $snapshot = [
                'contract' => self::CONTRACT,
                'bank_return_evidence_id' => $return->id,
                'original_payment_execution_id' => $execution->id,
                'original_posted_journal_entry_id' => $journal->id,
                'return_statement_line_id' => $return->return_statement_line_id,
                'reversed_by' => $actor->id,
            ];
            $hash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $existing = BankSupplierPaymentReversalExecution::withoutGlobalScope('property')
                ->where('bank_return_evidence_id', $return->id)->orWhere('original_payment_execution_id', $execution->id)
                ->lockForUpdate()->first();
            if ($existing) {
                if ($existing->source_identity_hash === $hash) {
                    return $existing->fresh();
                }
                throw new DomainException('Conflicting bank supplier-payment reversal evidence already exists.');
            }

            return BankSupplierPaymentReversalExecution::create([
                'bank_return_evidence_id' => $return->id,
                'original_payment_execution_id' => $execution->id,
                'original_posted_journal_entry_id' => $journal->id,
                'property_id' => $return->property_id,
                'vendor_id' => $return->vendor_id,
                'controlled_bank_account_id' => $return->controlled_bank_account_id,
                'operational_gl_account_id' => $return->operational_gl_account_id,
                'currency_code' => $return->currency_code,
                'reversal_amount' => $this->amount($return->return_amount),
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
                'source_identity_hash' => $hash,
                'source_snapshot' => $snapshot,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->fresh();
        });
    }

    private function amount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
