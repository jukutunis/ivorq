<?php

namespace Modules\Finance\Payables\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\Payables\Enums\PaymentProposalStatusEnum;
use Modules\Finance\Payables\Models\PaymentProposal;
use Modules\Finance\Payables\Models\PaymentProposalItem;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Foundation\User\Models\User;
use Throwable;

class PaymentProposalService
{
    public const CREATE_PERMISSION = 'finance.payables.payment-proposal.create';
    public const CANCEL_PERMISSION = 'finance.payables.payment-proposal.cancel';

    private const SOURCE_TYPE = 'SupplierInvoice';
    private const POSTING_EVENT = 'SupplierInvoiceGrniClearingApLiability';

    public function createDraft(array $journalEntryIds, User $actor): PaymentProposal
    {
        return DB::transaction(function () use ($journalEntryIds, $actor) {
            $actor = $this->resolveAuthorizedActor($actor, self::CREATE_PERMISSION);
            $ids = $this->normalizeIds($journalEntryIds);
            $journals = $this->eligibleJournals($ids);
            $evidence = $this->proposalEvidence($journals);

            $this->assertActorCanAccessProperty($actor, $evidence['property_id']);

            $existing = PaymentProposal::with('items')
                ->where('property_id', $evidence['property_id'])
                ->where('vendor_id', $evidence['vendor_id'])
                ->where('currency_code', $evidence['currency_code'])
                ->where('source_fingerprint', $evidence['source_fingerprint'])
                ->where('status', PaymentProposalStatusEnum::DRAFT->value)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingDraftMatches($existing, $actor, $ids);

                return $existing->fresh(['items']);
            }

            $collision = PaymentProposalItem::where('property_id', $evidence['property_id'])
                ->whereIn('source_journal_entry_id', $ids)
                ->where('is_active', true)
                ->lockForUpdate()
                ->exists();

            if ($collision) {
                throw new DomainException('Posted AP liability source is already selected by an active Draft Payment Proposal.');
            }

            $proposal = new PaymentProposal([
                'property_id' => $evidence['property_id'],
                'vendor_id' => $evidence['vendor_id'],
                'proposal_number' => $this->nextProposalNumber($evidence['property_id'], $evidence['proposal_year']),
                'currency_code' => $evidence['currency_code'],
                'status' => PaymentProposalStatusEnum::DRAFT->value,
                'source_fingerprint' => $evidence['source_fingerprint'],
                'total_amount' => $evidence['total_amount'],
            ]);
            $proposal->created_by = $actor->id;
            $proposal->updated_by = $actor->id;
            $proposal->save();

            foreach ($evidence['items'] as $item) {
                $proposal->items()->create($item + [
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            return $proposal->fresh(['items']);
        });
    }

    public function cancelDraft(string $proposalId, User $actor, string $reason): PaymentProposal
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Payment Proposal cancellation requires a meaningful reason.');
        }

        return DB::transaction(function () use ($proposalId, $actor, $reason) {
            $actor = $this->resolveAuthorizedActor($actor, self::CANCEL_PERMISSION);

            $proposal = PaymentProposal::with('items')
                ->whereKey($proposalId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $proposal->property_id);

            if ($proposal->status === PaymentProposalStatusEnum::CANCELLED) {
                if ($proposal->cancelled_by === $actor->id && $proposal->cancellation_reason === $reason) {
                    return $proposal->fresh(['items']);
                }

                throw new DomainException('Conflicting Payment Proposal cancellation evidence already exists.');
            }

            if ($proposal->status !== PaymentProposalStatusEnum::DRAFT) {
                throw new DomainException('Only Draft Payment Proposals can be cancelled.');
            }

            $proposal->status = PaymentProposalStatusEnum::CANCELLED;
            $proposal->cancelled_by = $actor->id;
            $proposal->cancelled_at = now();
            $proposal->cancellation_reason = $reason;
            $proposal->updated_by = $actor->id;
            $proposal->save();

            PaymentProposalItem::where('payment_proposal_id', $proposal->id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'updated_by' => $actor->id,
                    'updated_at' => now(),
                ]);

            return $proposal->fresh(['items']);
        });
    }

    private function resolveAuthorizedActor(User $actor, string $permission): User
    {
        $freshActor = User::where('id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (!$freshActor) {
            throw new AuthorizationException('Payment Proposal action requires an active actor.');
        }

        try {
            $authorized = $freshActor->can($permission);
        } catch (Throwable) {
            throw new AuthorizationException('Payment Proposal permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Payment Proposal permission is required.');
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
            throw new AuthorizationException('Payment Proposal action requires active property access.');
        }
    }

    private function normalizeIds(array $journalEntryIds): array
    {
        $ids = collect($journalEntryIds)
            ->map(fn (mixed $id): string => (string) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($ids === [] || count($ids) !== count($journalEntryIds)) {
            throw new DomainException('Payment Proposal requires unique posted AP liability sources.');
        }

        return $ids;
    }

    /**
     * @return Collection<int, JournalEntry>
     */
    private function eligibleJournals(array $ids): Collection
    {
        $journals = JournalEntry::with(['candidate', 'lines'])
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->sortBy('id')
            ->values();

        if ($journals->count() !== count($ids)) {
            throw new DomainException('Payment Proposal source obligation is missing.');
        }

        return $journals;
    }

    private function proposalEvidence(Collection $journals): array
    {
        $items = [];
        $propertyId = null;
        $vendorId = null;
        $currency = null;
        $totalCents = 0;
        $ids = [];

        foreach ($journals as $journal) {
            $candidate = $journal->candidate;
            $invoice = SupplierInvoice::withoutGlobalScope('property')
                ->whereKey($journal->source_id)
                ->lockForUpdate()
                ->first();

            if (!$candidate || !$invoice) {
                throw new DomainException('Payment Proposal source lineage is incomplete.');
            }

            if ($journal->status !== JournalStatusEnum::Posted
                || $journal->source_module !== 'Payables'
                || $journal->source_type !== self::SOURCE_TYPE
                || $journal->posting_event !== self::POSTING_EVENT
                || $candidate->status !== JournalCandidateStatusEnum::APPROVED
                || $candidate->source_grni_candidate_id === null
                || $candidate->source_grni_journal_entry_id === null
                || $invoice->status !== SupplierInvoice::STATUS_APPROVED) {
                throw new DomainException('Payment Proposal source obligation is not eligible.');
            }

            $sourceCurrency = $invoice->currency_code;
            $sourceVendorId = $invoice->vendor_id;
            $sourcePropertyId = $invoice->property_id;

            $propertyId ??= $sourcePropertyId;
            $vendorId ??= $sourceVendorId;
            $currency ??= $sourceCurrency;

            if ($propertyId !== $sourcePropertyId || $vendorId !== $sourceVendorId || $currency !== $sourceCurrency) {
                throw new DomainException('Payment Proposal sources must share the same property, vendor, and currency.');
            }

            $amount = $this->journalAmount($journal);
            $totalCents += $this->amountToCents($amount);
            $ids[] = $journal->id;

            $items[] = [
                'property_id' => $sourcePropertyId,
                'source_journal_entry_id' => $journal->id,
                'source_journal_candidate_id' => $candidate->id,
                'supplier_invoice_id' => $invoice->id,
                'vendor_id' => $sourceVendorId,
                'currency_code' => $sourceCurrency,
                'source_amount' => $amount,
                'is_active' => true,
                'source_snapshot' => [
                    'invoice_number' => $invoice->invoice_number,
                    'posted_at' => $journal->posted_at?->toIso8601String(),
                    'posting_date' => $journal->posting_date?->toDateString(),
                    'candidate_id' => $candidate->id,
                    'source_grni_candidate_id' => $candidate->source_grni_candidate_id,
                    'source_grni_journal_entry_id' => $candidate->source_grni_journal_entry_id,
                ],
            ];
        }

        return [
            'property_id' => $propertyId,
            'vendor_id' => $vendorId,
            'currency_code' => $currency,
            'proposal_year' => $journals->first()->transaction_date?->format('Y') ?? '0000',
            'source_fingerprint' => hash('sha256', implode('|', $ids)),
            'total_amount' => number_format($totalCents / 100, 2, '.', ''),
            'items' => $items,
        ];
    }

    private function assertExistingDraftMatches(PaymentProposal $proposal, User $actor, array $ids): void
    {
        $activeIds = $proposal->items
            ->where('is_active', true)
            ->pluck('source_journal_entry_id')
            ->sort()
            ->values()
            ->all();

        if ($proposal->created_by !== $actor->id || $activeIds !== $ids) {
            throw new DomainException('Conflicting active Draft Payment Proposal already exists for this source set.');
        }
    }

    private function nextProposalNumber(string $propertyId, string $year): string
    {
        $latest = PaymentProposal::where('property_id', $propertyId)
            ->where('proposal_number', 'like', "PP-{$year}-%")
            ->lockForUpdate()
            ->orderByDesc('proposal_number')
            ->first();

        $sequence = 1;
        if ($latest) {
            $parts = explode('-', $latest->proposal_number);
            $sequence = ((int) end($parts)) + 1;
        }

        return "PP-{$year}-" . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    private function journalAmount(JournalEntry $journal): string
    {
        $debits = $journal->lines->sum(fn ($line): float => (float) $line->debit_amount);
        $credits = $journal->lines->sum(fn ($line): float => (float) $line->credit_amount);

        return number_format(max($debits, $credits), 2, '.', '');
    }

    private function amountToCents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
