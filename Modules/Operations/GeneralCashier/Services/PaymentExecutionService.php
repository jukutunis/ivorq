<?php

namespace Modules\Operations\GeneralCashier\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\Payables\Enums\PaymentProposalStatusEnum;
use Modules\Finance\Payables\Models\PaymentProposalItem;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierPaymentInstrumentTypeEnum;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Throwable;

class PaymentExecutionService
{
    public const PERMISSION = 'finance.general-cashier.payment.execute';

    public function __construct(
        private readonly GeneralCashierOperationalFoundationService $cashierFoundationService,
    ) {}

    public function recordCashExecution(
        string $paymentProposalItemId,
        string $cashierSessionId,
        string $cashierPaymentInstrumentId,
        ?User $actor
    ): PaymentExecution {
        return DB::transaction(function () use (
            $paymentProposalItemId,
            $cashierSessionId,
            $cashierPaymentInstrumentId,
            $actor
        ): PaymentExecution {
            $actor = $this->resolveAuthorizedActor($actor);

            $item = PaymentProposalItem::with('proposal')
                ->whereKey($paymentProposalItemId)
                ->lockForUpdate()
                ->firstOrFail();

            $proposal = $item->proposal;
            if (!$proposal) {
                throw new DomainException('Payment Proposal source evidence is unavailable.');
            }

            $this->assertActorCanAccessProperty($actor, $item->property_id);
            $this->assertApprovedProposalItem($item);

            $sourceJournal = JournalEntry::with('lines')
                ->whereKey($item->source_journal_entry_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPostedApLiabilitySource($item, $sourceJournal);

            $operationalContext = $this->cashierFoundationService->resolveOperationalContext(
                $cashierSessionId,
                $cashierPaymentInstrumentId,
                $actor
            );

            $this->assertCashOperationalContext($item, $operationalContext);

            $existing = PaymentExecution::where('payment_proposal_item_id', $item->id)
                ->orWhere('source_journal_entry_id', $item->source_journal_entry_id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingExecutionMatches(
                    $existing,
                    $item,
                    $operationalContext,
                    $actor->id
                );

                return $existing->fresh();
            }

            $execution = new PaymentExecution([
                'property_id' => $item->property_id,
                'vendor_id' => $item->vendor_id,
                'payment_proposal_id' => $item->payment_proposal_id,
                'payment_proposal_item_id' => $item->id,
                'source_journal_entry_id' => $item->source_journal_entry_id,
                'source_journal_candidate_id' => $item->source_journal_candidate_id,
                'supplier_invoice_id' => $item->supplier_invoice_id,
                'cashier_session_id' => $operationalContext['cashier_session_id'],
                'cashier_payment_instrument_id' => $operationalContext['cashier_payment_instrument_id'],
                'operational_gl_account_id' => $operationalContext['operational_gl_account_id'],
                'currency_code' => $item->currency_code,
                'source_amount' => $this->amountString($item->source_amount),
                'executed_by' => $actor->id,
                'executed_at' => now(),
                'source_snapshot' => [
                    'payment_proposal_id' => $item->payment_proposal_id,
                    'payment_proposal_item_id' => $item->id,
                    'source_journal_entry_id' => $item->source_journal_entry_id,
                    'source_journal_candidate_id' => $item->source_journal_candidate_id,
                    'supplier_invoice_id' => $item->supplier_invoice_id,
                    'vendor_id' => $item->vendor_id,
                    'currency_code' => $item->currency_code,
                    'source_amount' => $this->amountString($item->source_amount),
                    'cashier_session_id' => $operationalContext['cashier_session_id'],
                    'cashier_payment_instrument_id' => $operationalContext['cashier_payment_instrument_id'],
                    'operational_gl_account_id' => $operationalContext['operational_gl_account_id'],
                ],
            ]);
            $execution->created_by = $actor->id;
            $execution->updated_by = $actor->id;
            $execution->save();

            return $execution->fresh();
        });
    }

    private function resolveAuthorizedActor(?User $actor): User
    {
        if (!$actor) {
            throw new AuthorizationException('Payment Execution requires an active actor.');
        }

        $freshActor = User::where('id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (!$freshActor) {
            throw new AuthorizationException('Payment Execution requires an active actor.');
        }

        try {
            $authorized = $freshActor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Payment Execution permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Payment Execution permission is required.');
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
            throw new AuthorizationException('Payment Execution requires active property access.');
        }
    }

    private function assertApprovedProposalItem(PaymentProposalItem $item): void
    {
        if (!$item->is_active) {
            throw new DomainException('Only active Payment Proposal Items can be executed.');
        }

        if ($item->proposal->status !== PaymentProposalStatusEnum::APPROVED) {
            throw new DomainException('Only approved Payment Proposals can be executed.');
        }

        if (
            $item->proposal->property_id !== $item->property_id ||
            $item->proposal->vendor_id !== $item->vendor_id ||
            $item->proposal->currency_code !== $item->currency_code
        ) {
            throw new DomainException('Payment Proposal Item conflicts with parent proposal scope.');
        }
    }

    private function assertPostedApLiabilitySource(PaymentProposalItem $item, JournalEntry $sourceJournal): void
    {
        if (
            $sourceJournal->status !== JournalStatusEnum::Posted ||
            $sourceJournal->property_id !== $item->property_id ||
            $sourceJournal->source_module !== 'Payables' ||
            $sourceJournal->source_type !== 'SupplierInvoice' ||
            $sourceJournal->source_id !== $item->supplier_invoice_id ||
            $sourceJournal->journal_candidate_id !== $item->source_journal_candidate_id ||
            $sourceJournal->posting_event !== 'SupplierInvoiceGrniClearingApLiability'
        ) {
            throw new DomainException('Payment Proposal Item source is not posted AP liability evidence.');
        }

        if ($this->amountString($this->journalAmount($sourceJournal)) !== $this->amountString($item->source_amount)) {
            throw new DomainException('Payment Proposal Item amount conflicts with posted AP liability source.');
        }
    }

    private function assertCashOperationalContext(PaymentProposalItem $item, array $operationalContext): void
    {
        if ($operationalContext['property_id'] !== $item->property_id) {
            throw new AuthorizationException('Payment Execution requires same-property cashier context.');
        }

        if ($operationalContext['instrument_type'] !== CashierPaymentInstrumentTypeEnum::CASH->value) {
            throw new DomainException('Only CASH General Cashier instruments are supported for this payment execution slice.');
        }
    }

    private function assertExistingExecutionMatches(
        PaymentExecution $existing,
        PaymentProposalItem $item,
        array $operationalContext,
        string $actorId
    ): void {
        if (
            $existing->property_id === $item->property_id &&
            $existing->vendor_id === $item->vendor_id &&
            $existing->payment_proposal_id === $item->payment_proposal_id &&
            $existing->payment_proposal_item_id === $item->id &&
            $existing->source_journal_entry_id === $item->source_journal_entry_id &&
            $existing->source_journal_candidate_id === $item->source_journal_candidate_id &&
            $existing->supplier_invoice_id === $item->supplier_invoice_id &&
            $existing->cashier_session_id === $operationalContext['cashier_session_id'] &&
            $existing->cashier_payment_instrument_id === $operationalContext['cashier_payment_instrument_id'] &&
            $existing->operational_gl_account_id === $operationalContext['operational_gl_account_id'] &&
            $existing->currency_code === $item->currency_code &&
            $this->amountString($existing->source_amount) === $this->amountString($item->source_amount) &&
            $existing->executed_by === $actorId &&
            $existing->executed_at !== null
        ) {
            return;
        }

        throw new DomainException('Conflicting Payment Execution evidence already exists.');
    }

    private function journalAmount(JournalEntry $journal): string
    {
        if ($journal->lines->isEmpty()) {
            throw new DomainException('Posted AP liability JournalEntry has no line evidence.');
        }

        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($journal->lines as $line) {
            $debitTotal += $this->amountToCents($line->debit_amount);
            $creditTotal += $this->amountToCents($line->credit_amount);
        }

        if ($debitTotal !== $creditTotal || $debitTotal <= 0) {
            throw new DomainException('Posted AP liability JournalEntry is not balanced.');
        }

        return number_format($debitTotal / 100, 2, '.', '');
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
