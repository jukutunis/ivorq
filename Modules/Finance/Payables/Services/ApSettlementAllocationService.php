<?php

namespace Modules\Finance\Payables\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\Payables\Models\ApSettlementAllocation;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\CashSupplierPaymentReversalExecution;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Throwable;

class ApSettlementAllocationService
{
    public const PERMISSION = 'finance.payables.ap-settlement.allocate';
    public const CONTRACT = 'ap_settlement_allocation_v1';

    public function __construct(
        private readonly ApOutstandingProjectionService $outstandingProjectionService
    ) {}

    public function allocate(
        string $apJournalEntryId,
        string $paymentJournalEntryId,
        mixed $amount,
        ?User $actor
    ): ApSettlementAllocation {
        return DB::transaction(function () use ($apJournalEntryId, $paymentJournalEntryId, $amount, $actor): ApSettlementAllocation {
            $actor = $this->resolveAuthorizedActor($actor);

            $apJournal = JournalEntry::with('lines')
                ->whereKey($apJournalEntryId)
                ->lockForUpdate()
                ->firstOrFail();

            $paymentJournal = JournalEntry::with('lines')
                ->whereKey($paymentJournalEntryId)
                ->lockForUpdate()
                ->firstOrFail();

            $paymentExecution = PaymentExecution::whereKey($paymentJournal->source_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $apJournal->property_id);
            $this->assertAllocationSources($apJournal, $paymentJournal, $paymentExecution);

            $amount = $this->amountString($amount);
            if ($this->amountToCents($amount) <= 0) {
                throw new DomainException('AP settlement allocation amount must be positive.');
            }

            $identityHash = $this->sourceIdentityHash($apJournal, $paymentJournal, $paymentExecution, $amount, $actor->id);
            $snapshot = $this->sourceSnapshot($apJournal, $paymentJournal, $paymentExecution, $amount, $actor->id);

            $existing = ApSettlementAllocation::where('payment_journal_entry_id', $paymentJournal->id)
                ->orWhere('payment_execution_id', $paymentExecution->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingAllocationMatches($existing, $apJournal, $paymentJournal, $paymentExecution, $amount, $actor->id, $identityHash);

                return $existing->fresh();
            }

            $outstanding = $this->outstandingProjectionService->outstandingForPostedApJournal($apJournal);
            if ($this->amountToCents($amount) > $this->amountToCents($outstanding)) {
                throw new DomainException('AP settlement allocation amount exceeds outstanding AP liability.');
            }

            $allocation = new ApSettlementAllocation([
                'property_id' => $apJournal->property_id,
                'vendor_id' => $paymentExecution->vendor_id,
                'currency_code' => $paymentExecution->currency_code,
                'ap_journal_entry_id' => $apJournal->id,
                'payment_journal_entry_id' => $paymentJournal->id,
                'payment_execution_id' => $paymentExecution->id,
                'allocation_amount' => $amount,
                'allocated_by' => $actor->id,
                'allocated_at' => now(),
                'source_identity_hash' => $identityHash,
                'source_snapshot' => $snapshot,
            ]);
            $allocation->created_by = $actor->id;
            $allocation->updated_by = $actor->id;
            $allocation->save();

            return $allocation->fresh();
        });
    }

    private function resolveAuthorizedActor(?User $actor): User
    {
        if (!$actor) {
            throw new AuthorizationException('AP settlement allocation requires an active actor.');
        }

        $freshActor = User::where('id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (!$freshActor) {
            throw new AuthorizationException('AP settlement allocation requires an active actor.');
        }

        try {
            $authorized = $freshActor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('AP settlement allocation permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('AP settlement allocation permission is required.');
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
            throw new AuthorizationException('AP settlement allocation requires active property access.');
        }
    }

    private function assertAllocationSources(
        JournalEntry $apJournal,
        JournalEntry $paymentJournal,
        PaymentExecution $paymentExecution
    ): void {
        if (
            $apJournal->status !== JournalStatusEnum::Posted ||
            $apJournal->source_module !== 'Payables' ||
            $apJournal->source_type !== 'SupplierInvoice' ||
            $apJournal->posting_event !== 'SupplierInvoiceGrniClearingApLiability'
        ) {
            throw new DomainException('AP settlement allocation requires posted AP liability JournalEntry evidence.');
        }

        if (
            $paymentJournal->status !== JournalStatusEnum::Posted ||
            $paymentJournal->source_module !== 'GeneralCashier' ||
            $paymentJournal->source_type !== 'PaymentExecution' ||
            $paymentJournal->source_id !== $paymentExecution->id ||
            $paymentJournal->posting_event !== 'SupplierPaymentCashDisbursement'
        ) {
            throw new DomainException('AP settlement allocation requires posted supplier payment JournalEntry evidence.');
        }

        if (
            $paymentExecution->property_id !== $apJournal->property_id ||
            $paymentJournal->property_id !== $apJournal->property_id ||
            $paymentExecution->source_journal_entry_id !== $apJournal->id ||
            $paymentExecution->supplier_invoice_id !== $apJournal->source_id ||
            $paymentExecution->source_journal_candidate_id !== $apJournal->journal_candidate_id
        ) {
            throw new DomainException('Supplier payment evidence conflicts with AP liability source.');
        }

        if (CashSupplierPaymentReversalExecution::where('original_posted_journal_entry_id', $paymentJournal->id)->exists()) {
            throw new DomainException('Reversed supplier payment cannot be allocated without approved treatment.');
        }
    }

    private function assertExistingAllocationMatches(
        ApSettlementAllocation $existing,
        JournalEntry $apJournal,
        JournalEntry $paymentJournal,
        PaymentExecution $paymentExecution,
        string $amount,
        string $actorId,
        string $identityHash
    ): void {
        if (
            $existing->property_id === $apJournal->property_id &&
            $existing->vendor_id === $paymentExecution->vendor_id &&
            $existing->currency_code === $paymentExecution->currency_code &&
            $existing->ap_journal_entry_id === $apJournal->id &&
            $existing->payment_journal_entry_id === $paymentJournal->id &&
            $existing->payment_execution_id === $paymentExecution->id &&
            $this->amountString($existing->allocation_amount) === $amount &&
            $existing->allocated_by === $actorId &&
            $existing->allocated_at !== null &&
            $existing->source_identity_hash === $identityHash
        ) {
            return;
        }

        throw new DomainException('Conflicting AP settlement allocation evidence already exists.');
    }

    private function sourceIdentityHash(
        JournalEntry $apJournal,
        JournalEntry $paymentJournal,
        PaymentExecution $paymentExecution,
        string $amount,
        string $actorId
    ): string {
        return hash('sha256', implode('|', [
            self::CONTRACT,
            $apJournal->id,
            $paymentJournal->id,
            $paymentExecution->id,
            $paymentExecution->vendor_id,
            $paymentExecution->currency_code,
            $amount,
            $actorId,
        ]));
    }

    private function sourceSnapshot(
        JournalEntry $apJournal,
        JournalEntry $paymentJournal,
        PaymentExecution $paymentExecution,
        string $amount,
        string $actorId
    ): array {
        return [
            'contract' => self::CONTRACT,
            'ap_journal_entry_id' => $apJournal->id,
            'payment_journal_entry_id' => $paymentJournal->id,
            'payment_execution_id' => $paymentExecution->id,
            'property_id' => $apJournal->property_id,
            'vendor_id' => $paymentExecution->vendor_id,
            'currency_code' => $paymentExecution->currency_code,
            'allocation_amount' => $amount,
            'allocated_by' => $actorId,
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
