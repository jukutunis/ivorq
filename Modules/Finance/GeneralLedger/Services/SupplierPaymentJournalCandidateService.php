<?php

namespace Modules\Finance\GeneralLedger\Services;

use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityMappingNotFoundException;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityValidationException;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\CashReturnEvidence;
use Modules\Operations\GeneralCashier\Models\CashSupplierPaymentReversalExecution;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Throwable;

class SupplierPaymentJournalCandidateService
{
    public const PERMISSION = 'finance.payables.supplier-payment.candidate.create';
    public const SOURCE_TYPE = 'PaymentExecution';
    public const POSTING_EVENT = 'SupplierPaymentCashDisbursement';
    public const REVERSAL_SOURCE_TYPE = 'CashSupplierPaymentReversalExecution';
    public const REVERSAL_POSTING_EVENT = 'SupplierPaymentCashReturnReversal';

    public function __construct(
        private readonly OperationalIdentityMappingService $mappingService,
        private readonly OperationalIdentityValidationService $validationService,
    ) {}

    public function createForPaymentExecution(string $paymentExecutionId): JournalCandidate
    {
        return DB::transaction(function () use ($paymentExecutionId): JournalCandidate {
            $actor = $this->resolveActiveActor();

            $execution = PaymentExecution::whereKey($paymentExecutionId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $execution->property_id);

            $sourceJournal = JournalEntry::with('lines')
                ->whereKey($execution->source_journal_entry_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPaymentExecutionSource($execution, $sourceJournal);
            $candidateDate = Carbon::parse($execution->executed_at)->toDateString();
            $accountEvidence = $this->resolveAccountEvidence($execution, $sourceJournal, $candidateDate);
            $amount = $this->assertSupportedAmount($execution, $sourceJournal, $accountEvidence);
            $metadata = $this->candidateMetadata($execution, $sourceJournal, $accountEvidence, $amount);
            $identity = $this->candidateIdentity($execution->property_id, $execution->id);

            $existing = JournalCandidate::where($identity)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingCandidateMatches($existing, $metadata, $amount);

                return $existing->fresh(['lines']);
            }

            $candidate = new JournalCandidate($identity + [
                'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
                'candidate_date' => $candidateDate,
                'description' => 'Supplier Payment Cash Disbursement Candidate for Payment Execution ' . $execution->id,
                'metadata' => $metadata,
            ]);
            $candidate->created_by = $actor->id;
            $candidate->updated_by = $actor->id;
            $candidate->save();

            $candidate->lines()->create([
                'operational_identity' => OperationalIdentityEnum::AP_CONTROL->value,
                'entry_type' => EntryTypeEnum::DEBIT->value,
                'amount' => $amount,
                'cost_center_id' => $accountEvidence['ap_mapping']->cost_center_id,
                'notes' => 'Debit AP liability control for executed supplier payment.',
            ]);

            $candidate->lines()->create([
                'operational_identity' => OperationalIdentityEnum::CASH_AND_BANK->value,
                'entry_type' => EntryTypeEnum::CREDIT->value,
                'amount' => $amount,
                'cost_center_id' => $accountEvidence['cash_mapping']->cost_center_id,
                'notes' => 'Credit General Cashier cash account for executed supplier payment.',
            ]);

            return $candidate->fresh(['lines']);
        });
    }

    public function createForCashReversalExecution(string $reversalExecutionId): JournalCandidate
    {
        return DB::transaction(function () use ($reversalExecutionId): JournalCandidate {
            $actor = $this->resolveActiveActor();

            $reversal = CashSupplierPaymentReversalExecution::whereKey($reversalExecutionId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $reversal->property_id);

            $returnEvidence = CashReturnEvidence::whereKey($reversal->cash_return_evidence_id)
                ->lockForUpdate()
                ->firstOrFail();

            $originalPaymentJournal = JournalEntry::with('lines')
                ->whereKey($reversal->original_posted_journal_entry_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCashReversalExecutionSource($reversal, $returnEvidence, $originalPaymentJournal);
            $candidateDate = Carbon::parse($reversal->reversed_at)->toDateString();
            $accountEvidence = $this->resolveCashReversalAccountEvidence($reversal, $originalPaymentJournal, $candidateDate);
            $amount = $this->assertSupportedCashReversalAmount($reversal, $returnEvidence, $accountEvidence);
            $metadata = $this->cashReversalCandidateMetadata($reversal, $returnEvidence, $originalPaymentJournal, $accountEvidence, $amount);
            $identity = $this->cashReversalCandidateIdentity($reversal->property_id, $reversal->id);

            $existing = JournalCandidate::where($identity)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingCashReversalCandidateMatches($existing, $metadata, $amount);

                return $existing->fresh(['lines']);
            }

            $candidate = new JournalCandidate($identity + [
                'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
                'candidate_date' => $candidateDate,
                'description' => 'Supplier Payment Cash Return Reversal Candidate for Reversal Execution ' . $reversal->id,
                'metadata' => $metadata,
            ]);
            $candidate->created_by = $actor->id;
            $candidate->updated_by = $actor->id;
            $candidate->save();

            $candidate->lines()->create([
                'operational_identity' => OperationalIdentityEnum::CASH_AND_BANK->value,
                'entry_type' => EntryTypeEnum::DEBIT->value,
                'amount' => $amount,
                'cost_center_id' => $accountEvidence['cash_mapping']->cost_center_id,
                'notes' => 'Debit cash control for observed supplier payment cash return.',
            ]);

            $candidate->lines()->create([
                'operational_identity' => OperationalIdentityEnum::AP_CONTROL->value,
                'entry_type' => EntryTypeEnum::CREDIT->value,
                'amount' => $amount,
                'cost_center_id' => $accountEvidence['ap_mapping']->cost_center_id,
                'notes' => 'Credit AP liability control for full supplier payment reversal.',
            ]);

            return $candidate->fresh(['lines']);
        });
    }

    private function resolveActiveActor(): User
    {
        $authUser = Auth::user();

        if (!$authUser) {
            throw new AuthorizationException('Supplier payment candidate creation requires an authenticated actor.');
        }

        $actor = User::where('id', $authUser->id)
            ->where('is_active', true)
            ->first();

        if (!$actor) {
            throw new AuthorizationException('Supplier payment candidate creation actor is inactive or unresolved.');
        }

        try {
            $authorized = $actor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Supplier payment candidate creation permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Supplier payment candidate creation permission is required.');
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
            throw new AuthorizationException('Supplier payment candidate creation requires active property access.');
        }
    }

    private function assertPaymentExecutionSource(PaymentExecution $execution, JournalEntry $sourceJournal): void
    {
        if (
            $sourceJournal->status !== JournalStatusEnum::Posted ||
            $sourceJournal->property_id !== $execution->property_id ||
            $sourceJournal->source_module !== 'Payables' ||
            $sourceJournal->source_type !== 'SupplierInvoice' ||
            $sourceJournal->source_id !== $execution->supplier_invoice_id ||
            $sourceJournal->journal_candidate_id !== $execution->source_journal_candidate_id ||
            $sourceJournal->posting_event !== 'SupplierInvoiceGrniClearingApLiability'
        ) {
            throw new DomainException('Payment Execution source is not posted AP liability evidence.');
        }
    }

    private function assertCashReversalExecutionSource(
        CashSupplierPaymentReversalExecution $reversal,
        CashReturnEvidence $returnEvidence,
        JournalEntry $originalPaymentJournal
    ): void {
        if (
            $returnEvidence->id !== $reversal->cash_return_evidence_id ||
            $returnEvidence->payment_execution_id !== $reversal->original_payment_execution_id ||
            $returnEvidence->posted_journal_entry_id !== $reversal->original_posted_journal_entry_id ||
            $returnEvidence->property_id !== $reversal->property_id ||
            $returnEvidence->vendor_id !== $reversal->vendor_id ||
            $returnEvidence->operational_gl_account_id !== $reversal->operational_gl_account_id ||
            $returnEvidence->currency_code !== $reversal->currency_code ||
            $this->amountString($returnEvidence->return_amount) !== $this->amountString($reversal->reversal_amount)
        ) {
            throw new DomainException('Cash supplier payment reversal execution conflicts with Cash Return evidence.');
        }

        if (
            $originalPaymentJournal->status !== JournalStatusEnum::Posted ||
            $originalPaymentJournal->property_id !== $reversal->property_id ||
            $originalPaymentJournal->source_module !== 'GeneralCashier' ||
            $originalPaymentJournal->source_type !== self::SOURCE_TYPE ||
            $originalPaymentJournal->source_id !== $reversal->original_payment_execution_id ||
            $originalPaymentJournal->posting_event !== self::POSTING_EVENT ||
            $originalPaymentJournal->journal_candidate_id === null ||
            $originalPaymentJournal->posting_date === null ||
            $originalPaymentJournal->posted_by === null ||
            $originalPaymentJournal->posted_at === null
        ) {
            throw new DomainException('Cash supplier payment reversal requires posted original supplier payment JournalEntry evidence.');
        }
    }

    private function resolveAccountEvidence(PaymentExecution $execution, JournalEntry $sourceJournal, string $candidateDate): array
    {
        $date = Carbon::parse($candidateDate);

        try {
            $apMapping = $this->mappingService->resolve($execution->property_id, OperationalIdentityEnum::AP_CONTROL, $date);
            $this->validationService->validate(OperationalIdentityEnum::AP_CONTROL, $apMapping->account);
            $this->assertActivePropertyAccount($apMapping->account, $execution->property_id, 'AP liability control');

            $cashMapping = $this->mappingService->resolve($execution->property_id, OperationalIdentityEnum::CASH_AND_BANK, $date);
            $this->validationService->validate(OperationalIdentityEnum::CASH_AND_BANK, $cashMapping->account);
            $this->assertActivePropertyAccount($cashMapping->account, $execution->property_id, 'cash and bank');
        } catch (OperationalIdentityMappingNotFoundException | OperationalIdentityValidationException | DomainException) {
            throw new DomainException('Supplier payment account evidence is unavailable for candidate creation.');
        }

        if ($cashMapping->account_id !== $execution->operational_gl_account_id) {
            throw new DomainException('Payment Execution cash account conflicts with active CASH_AND_BANK mapping.');
        }

        $postedCreditLines = $sourceJournal->lines
            ->filter(fn ($line): bool => $this->amountToCents($line->credit_amount) > 0)
            ->values();

        if ($postedCreditLines->count() !== 1) {
            throw new DomainException('Posted AP liability JournalEntry must contain exactly one credit AP line.');
        }

        $postedApLine = $postedCreditLines->first();

        if ($postedApLine->account_id !== $apMapping->account_id) {
            throw new DomainException('Posted AP liability account conflicts with active AP_CONTROL mapping.');
        }

        return [
            'ap_mapping' => $apMapping,
            'cash_mapping' => $cashMapping,
            'posted_ap_line' => $postedApLine,
        ];
    }

    private function resolveCashReversalAccountEvidence(
        CashSupplierPaymentReversalExecution $reversal,
        JournalEntry $originalPaymentJournal,
        string $candidateDate
    ): array {
        $date = Carbon::parse($candidateDate);

        try {
            $apMapping = $this->mappingService->resolve($reversal->property_id, OperationalIdentityEnum::AP_CONTROL, $date);
            $this->validationService->validate(OperationalIdentityEnum::AP_CONTROL, $apMapping->account);
            $this->assertActivePropertyAccount($apMapping->account, $reversal->property_id, 'AP liability control');

            $cashMapping = $this->mappingService->resolve($reversal->property_id, OperationalIdentityEnum::CASH_AND_BANK, $date);
            $this->validationService->validate(OperationalIdentityEnum::CASH_AND_BANK, $cashMapping->account);
            $this->assertActivePropertyAccount($cashMapping->account, $reversal->property_id, 'cash and bank');
        } catch (OperationalIdentityMappingNotFoundException | OperationalIdentityValidationException | DomainException) {
            throw new DomainException('Supplier payment reversal account evidence is unavailable for candidate creation.');
        }

        if ($cashMapping->account_id !== $reversal->operational_gl_account_id) {
            throw new DomainException('Cash supplier payment reversal account conflicts with active CASH_AND_BANK mapping.');
        }

        $amount = $this->amountString($reversal->reversal_amount);
        $originalApDebitLines = $originalPaymentJournal->lines
            ->filter(fn ($line): bool => $line->account_id === $apMapping->account_id
                && $this->amountString($line->debit_amount) === $amount
                && $this->amountToCents($line->credit_amount) === 0)
            ->values();

        $originalCashCreditLines = $originalPaymentJournal->lines
            ->filter(fn ($line): bool => $line->account_id === $cashMapping->account_id
                && $this->amountString($line->credit_amount) === $amount
                && $this->amountToCents($line->debit_amount) === 0)
            ->values();

        if ($originalApDebitLines->count() !== 1 || $originalCashCreditLines->count() !== 1) {
            throw new DomainException('Original supplier payment JournalEntry line evidence cannot support full cash reversal.');
        }

        return [
            'ap_mapping' => $apMapping,
            'cash_mapping' => $cashMapping,
            'original_ap_debit_line' => $originalApDebitLines->first(),
            'original_cash_credit_line' => $originalCashCreditLines->first(),
        ];
    }

    private function assertActivePropertyAccount(object $account, string $propertyId, string $label): void
    {
        if ($account->property_id !== $propertyId || !$account->is_active || $account->deleted_at !== null) {
            throw new DomainException($label . ' account is not active for the supplier payment property.');
        }
    }

    private function assertSupportedAmount(PaymentExecution $execution, JournalEntry $sourceJournal, array $accountEvidence): string
    {
        $executionAmount = $this->amountToCents($execution->source_amount);
        $postedApAmount = $this->amountToCents($accountEvidence['posted_ap_line']->credit_amount);
        $journalAmount = $this->journalAmountInCents($sourceJournal);

        if ($postedApAmount !== $journalAmount) {
            throw new DomainException('Payment Execution amount conflicts with posted AP liability evidence.');
        }

        if ($execution->payment_intent_key === null) {
            if ($executionAmount <= 0 || $executionAmount !== $postedApAmount) {
                throw new DomainException('Payment Execution amount conflicts with posted AP liability evidence.');
            }

            return number_format($executionAmount / 100, 2, '.', '');
        }

        if ($executionAmount <= 0 || $executionAmount > $postedApAmount) {
            throw new DomainException('Partial Payment Execution amount conflicts with posted AP liability evidence.');
        }

        return number_format($executionAmount / 100, 2, '.', '');
    }

    private function assertSupportedCashReversalAmount(
        CashSupplierPaymentReversalExecution $reversal,
        CashReturnEvidence $returnEvidence,
        array $accountEvidence
    ): string {
        $reversalAmount = $this->amountToCents($reversal->reversal_amount);
        $returnAmount = $this->amountToCents($returnEvidence->return_amount);
        $originalApAmount = $this->amountToCents($accountEvidence['original_ap_debit_line']->debit_amount);
        $originalCashAmount = $this->amountToCents($accountEvidence['original_cash_credit_line']->credit_amount);

        if (
            $reversalAmount <= 0 ||
            $reversalAmount !== $returnAmount ||
            $reversalAmount !== $originalApAmount ||
            $reversalAmount !== $originalCashAmount
        ) {
            throw new DomainException('Cash supplier payment reversal amount conflicts with source evidence.');
        }

        return number_format($reversalAmount / 100, 2, '.', '');
    }

    private function candidateMetadata(
        PaymentExecution $execution,
        JournalEntry $sourceJournal,
        array $accountEvidence,
        string $amount
    ): array {
        return [
            'contract' => 'supplier_payment_cash_disbursement_candidate_v1',
            'currency_code' => $execution->currency_code,
            'amount' => $amount,
            'payment_execution' => [
                'id' => $execution->id,
                'property_id' => $execution->property_id,
                'vendor_id' => $execution->vendor_id,
                'payment_proposal_id' => $execution->payment_proposal_id,
                'payment_proposal_item_id' => $execution->payment_proposal_item_id,
                'cashier_session_id' => $execution->cashier_session_id,
                'cashier_payment_instrument_id' => $execution->cashier_payment_instrument_id,
                'operational_gl_account_id' => $execution->operational_gl_account_id,
                'executed_by' => $execution->executed_by,
                'executed_at' => $execution->executed_at?->toISOString(),
            ],
            'posted_ap_liability' => [
                'journal_entry_id' => $sourceJournal->id,
                'journal_candidate_id' => $execution->source_journal_candidate_id,
                'supplier_invoice_id' => $execution->supplier_invoice_id,
                'journal_entry_line_id' => $accountEvidence['posted_ap_line']->id,
                'account_id' => $accountEvidence['posted_ap_line']->account_id,
                'amount' => $amount,
            ],
            'accounts' => [
                'ap_liability_control' => [
                    'operational_identity' => OperationalIdentityEnum::AP_CONTROL->value,
                    'mapping_id' => $accountEvidence['ap_mapping']->id,
                    'account_id' => $accountEvidence['ap_mapping']->account_id,
                ],
                'cash_and_bank' => [
                    'operational_identity' => OperationalIdentityEnum::CASH_AND_BANK->value,
                    'mapping_id' => $accountEvidence['cash_mapping']->id,
                    'account_id' => $accountEvidence['cash_mapping']->account_id,
                ],
            ],
        ];
    }

    private function cashReversalCandidateMetadata(
        CashSupplierPaymentReversalExecution $reversal,
        CashReturnEvidence $returnEvidence,
        JournalEntry $originalPaymentJournal,
        array $accountEvidence,
        string $amount
    ): array {
        return [
            'contract' => 'supplier_payment_cash_return_reversal_candidate_v1',
            'currency_code' => $reversal->currency_code,
            'amount' => $amount,
            'cash_supplier_payment_reversal_execution' => [
                'id' => $reversal->id,
                'cash_return_evidence_id' => $reversal->cash_return_evidence_id,
                'original_payment_execution_id' => $reversal->original_payment_execution_id,
                'original_posted_journal_entry_id' => $reversal->original_posted_journal_entry_id,
                'property_id' => $reversal->property_id,
                'vendor_id' => $reversal->vendor_id,
                'operational_gl_account_id' => $reversal->operational_gl_account_id,
                'reversed_by' => $reversal->reversed_by,
                'reversed_at' => $reversal->reversed_at?->toISOString(),
            ],
            'cash_return_evidence' => [
                'id' => $returnEvidence->id,
                'observed_return_date' => $returnEvidence->observed_return_date?->toDateString(),
                'source_reference' => $returnEvidence->source_reference,
                'recorded_by' => $returnEvidence->recorded_by,
            ],
            'original_payment_journal' => [
                'journal_entry_id' => $originalPaymentJournal->id,
                'journal_candidate_id' => $originalPaymentJournal->journal_candidate_id,
                'posting_event' => $originalPaymentJournal->posting_event,
                'posted_by' => $originalPaymentJournal->posted_by,
                'posted_at' => $originalPaymentJournal->posted_at?->toISOString(),
            ],
            'accounts' => [
                'cash_and_bank' => [
                    'operational_identity' => OperationalIdentityEnum::CASH_AND_BANK->value,
                    'mapping_id' => $accountEvidence['cash_mapping']->id,
                    'account_id' => $accountEvidence['cash_mapping']->account_id,
                    'original_journal_entry_line_id' => $accountEvidence['original_cash_credit_line']->id,
                ],
                'ap_liability_control' => [
                    'operational_identity' => OperationalIdentityEnum::AP_CONTROL->value,
                    'mapping_id' => $accountEvidence['ap_mapping']->id,
                    'account_id' => $accountEvidence['ap_mapping']->account_id,
                    'original_journal_entry_line_id' => $accountEvidence['original_ap_debit_line']->id,
                ],
            ],
        ];
    }

    private function candidateIdentity(string $propertyId, string $paymentExecutionId): array
    {
        return [
            'property_id' => $propertyId,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $paymentExecutionId,
            'posting_event' => self::POSTING_EVENT,
        ];
    }

    private function cashReversalCandidateIdentity(string $propertyId, string $reversalExecutionId): array
    {
        return [
            'property_id' => $propertyId,
            'source_type' => self::REVERSAL_SOURCE_TYPE,
            'source_id' => $reversalExecutionId,
            'posting_event' => self::REVERSAL_POSTING_EVENT,
        ];
    }

    private function assertExistingCandidateMatches(JournalCandidate $candidate, array $metadata, string $amount): void
    {
        if ($candidate->status !== JournalCandidateStatusEnum::PENDING_REVIEW) {
            throw new DomainException('Existing supplier payment JournalCandidate is no longer PENDING_REVIEW.');
        }

        if ($candidate->metadata !== $metadata) {
            throw new DomainException('Existing supplier payment JournalCandidate conflicts with current source evidence.');
        }

        $lines = $candidate->lines()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($lines->count() !== 2) {
            throw new DomainException('Existing supplier payment JournalCandidate line count conflicts with current source evidence.');
        }

        $expected = [
            [OperationalIdentityEnum::AP_CONTROL->value, EntryTypeEnum::DEBIT->value],
            [OperationalIdentityEnum::CASH_AND_BANK->value, EntryTypeEnum::CREDIT->value],
        ];

        foreach ($lines->values() as $index => $line) {
            [$identity, $entryType] = $expected[$index];

            if ($line->operational_identity->value !== $identity
                || $line->entry_type->value !== $entryType
                || number_format((float) $line->amount, 2, '.', '') !== $amount) {
                throw new DomainException('Existing supplier payment JournalCandidate lines conflict with current source evidence.');
            }
        }
    }

    private function assertExistingCashReversalCandidateMatches(JournalCandidate $candidate, array $metadata, string $amount): void
    {
        if ($candidate->status !== JournalCandidateStatusEnum::PENDING_REVIEW) {
            throw new DomainException('Existing supplier payment reversal JournalCandidate is no longer PENDING_REVIEW.');
        }

        if ($candidate->metadata !== $metadata) {
            throw new DomainException('Existing supplier payment reversal JournalCandidate conflicts with current source evidence.');
        }

        $lines = $candidate->lines()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($lines->count() !== 2) {
            throw new DomainException('Existing supplier payment reversal JournalCandidate line count conflicts with current source evidence.');
        }

        $expected = [
            [OperationalIdentityEnum::CASH_AND_BANK->value, EntryTypeEnum::DEBIT->value],
            [OperationalIdentityEnum::AP_CONTROL->value, EntryTypeEnum::CREDIT->value],
        ];

        foreach ($lines->values() as $index => $line) {
            [$identity, $entryType] = $expected[$index];

            if ($line->operational_identity->value !== $identity
                || $line->entry_type->value !== $entryType
                || number_format((float) $line->amount, 2, '.', '') !== $amount) {
                throw new DomainException('Existing supplier payment reversal JournalCandidate lines conflict with current source evidence.');
            }
        }
    }

    private function journalAmountInCents(JournalEntry $journal): int
    {
        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($journal->lines as $line) {
            $debitTotal += $this->amountToCents($line->debit_amount);
            $creditTotal += $this->amountToCents($line->credit_amount);
        }

        if ($debitTotal !== $creditTotal || $debitTotal <= 0) {
            throw new DomainException('Posted AP liability JournalEntry is not balanced.');
        }

        return $debitTotal;
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
