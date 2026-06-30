<?php

namespace Modules\Finance\Payables\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;

class ApGrniSettlementAgingProjectionService
{
    private const SOURCE_TYPE = 'SupplierInvoice';
    private const POSTING_EVENT = 'SupplierInvoiceGrniClearingApLiability';

    public function project(string $propertyId): array
    {
        $currentBusinessDate = $this->currentBusinessDate($propertyId);
        $invoices = $this->sourceInvoices($propertyId);
        $candidates = $this->sourceCandidates($propertyId);
        $journals = $this->sourceJournals($propertyId);

        $postedJournals = $journals
            ->filter(fn (JournalEntry $journal): bool => $journal->status === JournalStatusEnum::Posted)
            ->values();

        $ready = $postedJournals
            ->filter(fn (JournalEntry $journal): bool => $this->isReadyForProposal($journal, $invoices, $candidates))
            ->map(fn (JournalEntry $journal): array => $this->postedPayload($journal, $invoices, $candidates, $currentBusinessDate, 'Ready for Payment Proposal'))
            ->values()
            ->all();

        $aging = $postedJournals
            ->map(fn (JournalEntry $journal): array => $this->postedPayload($journal, $invoices, $candidates, $currentBusinessDate, 'Posted AP Liability'))
            ->values()
            ->all();

        $history = $candidates
            ->map(fn (JournalCandidate $candidate): array => $this->candidateHistoryPayload($candidate, $journals, $invoices))
            ->values()
            ->all();

        $held = [
            ...$this->heldInvoicePayloads($invoices, $journals),
            ...$this->heldCandidatePayloads($candidates, $journals, $invoices),
        ];

        return [
            'current_business_date' => $currentBusinessDate?->business_date?->toDateString(),
            'queues' => [
                'ready' => $ready,
                'aging' => $aging,
                'history' => $history,
                'held' => array_values($held),
            ],
            'summary' => [
                'ready_count' => count($ready),
                'aging_count' => count($aging),
                'history_count' => count($history),
                'held_count' => count($held),
            ],
        ];
    }

    private function currentBusinessDate(string $propertyId): ?PropertyBusinessDate
    {
        return PropertyBusinessDate::where('property_id', $propertyId)
            ->where('status', PropertyBusinessDateStatusEnum::Open->value)
            ->where('is_open', true)
            ->orderByDesc('business_date')
            ->first();
    }

    /**
     * @return Collection<string, SupplierInvoice>
     */
    private function sourceInvoices(string $propertyId): Collection
    {
        return SupplierInvoice::withoutGlobalScope('property')
            ->with(['vendor', 'property', 'threeWayMatch', 'approvedBy:id,name'])
            ->where('property_id', $propertyId)
            ->get()
            ->keyBy('id');
    }

    /**
     * @return Collection<string, JournalCandidate>
     */
    private function sourceCandidates(string $propertyId): Collection
    {
        return JournalCandidate::with([
            'lines',
            'approver:id,name',
            'creator:id,name',
            'rejector:id,name',
        ])
            ->where('property_id', $propertyId)
            ->where('source_type', self::SOURCE_TYPE)
            ->where('posting_event', self::POSTING_EVENT)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->keyBy('id');
    }

    /**
     * @return Collection<int, JournalEntry>
     */
    private function sourceJournals(string $propertyId): Collection
    {
        return JournalEntry::with([
            'lines.account',
            'candidate.approver:id,name',
            'draftFinalizationAuthorizer:id,name',
            'postingActor:id,name',
        ])
            ->where('property_id', $propertyId)
            ->where('source_module', 'Payables')
            ->where('source_type', self::SOURCE_TYPE)
            ->where('posting_event', self::POSTING_EVENT)
            ->whereNotNull('journal_candidate_id')
            ->orderByDesc('posted_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }

    private function isReadyForProposal(JournalEntry $journal, Collection $invoices, Collection $candidates): bool
    {
        $candidate = $candidates->get($journal->journal_candidate_id);
        $invoice = $invoices->get($journal->source_id);

        return $candidate !== null
            && $invoice !== null
            && $journal->status === JournalStatusEnum::Posted
            && $candidate->status === JournalCandidateStatusEnum::APPROVED
            && $candidate->source_grni_candidate_id !== null
            && $candidate->source_grni_journal_entry_id !== null
            && $invoice->status === SupplierInvoice::STATUS_APPROVED
            && $invoice->approved_by !== null
            && $invoice->approved_at !== null
            && $invoice->property_id === $journal->property_id
            && $invoice->currency_code === ($candidate->metadata['currency_code'] ?? $invoice->currency_code);
    }

    private function postedPayload(
        JournalEntry $journal,
        Collection $invoices,
        Collection $candidates,
        ?PropertyBusinessDate $currentBusinessDate,
        string $settlementStatus
    ): array {
        $candidate = $candidates->get($journal->journal_candidate_id);
        $invoice = $invoices->get($journal->source_id);
        $age = $this->ageEvidence($journal, $currentBusinessDate);

        return [
            'id' => $journal->id,
            'type' => 'posted_ap_liability',
            'settlement_status' => $settlementStatus,
            'invoice_number' => $invoice?->invoice_number,
            'vendor' => $this->vendorPayload($invoice),
            'property' => $invoice?->property?->name,
            'currency_code' => $invoice?->currency_code ?? ($candidate?->metadata['currency_code'] ?? null),
            'amount' => $this->journalAmount($journal, $candidate),
            'journal' => $this->journalEvidence($journal),
            'candidate' => $this->candidateEvidence($candidate),
            'source' => $this->sourceEvidence($candidate),
            'invoice_approval' => [
                'actor' => $invoice?->approvedBy?->name,
                'at' => $invoice?->approved_at?->toIso8601String(),
            ],
            'candidate_review' => [
                'status' => $candidate?->status?->value,
                'actor' => $candidate?->approver?->name,
                'at' => $candidate?->approved_at?->toIso8601String(),
            ],
            'age' => $age,
            'reason' => $age['available'] ? null : 'Age unavailable.',
        ];
    }

    private function candidateHistoryPayload(JournalCandidate $candidate, Collection $journals, Collection $invoices): array
    {
        $journal = $journals->firstWhere('journal_candidate_id', $candidate->id);
        $invoice = $invoices->get($candidate->source_id);

        return [
            'id' => $candidate->id,
            'type' => 'lifecycle_history',
            'settlement_status' => $this->candidateSettlementStatus($candidate, $journal),
            'invoice_number' => $invoice?->invoice_number,
            'vendor' => $this->vendorPayload($invoice),
            'property' => $invoice?->property?->name,
            'currency_code' => $invoice?->currency_code ?? $candidate->metadata['currency_code'] ?? null,
            'amount' => $candidate->metadata['amount'] ?? null,
            'journal' => $journal ? $this->journalEvidence($journal) : null,
            'candidate' => $this->candidateEvidence($candidate),
            'source' => $this->sourceEvidence($candidate),
            'reason' => $candidate->rejection_reason,
        ];
    }

    private function heldInvoicePayloads(Collection $invoices, Collection $journals): array
    {
        return $invoices
            ->filter(function (SupplierInvoice $invoice) use ($journals): bool {
                $hasPostedJournal = $journals->contains(function (JournalEntry $journal) use ($invoice): bool {
                    return $journal->source_id === $invoice->id && $journal->status === JournalStatusEnum::Posted;
                });

                return !$hasPostedJournal && (
                    $invoice->status === SupplierInvoice::STATUS_APPROVED
                    || $invoice->threeWayMatch?->status?->value === 'Exception'
                );
            })
            ->map(function (SupplierInvoice $invoice): array {
                $reason = $invoice->threeWayMatch?->status?->value === 'Exception'
                    ? 'Supplier Invoice has unresolved or exception match evidence.'
                    : 'Approved Supplier Invoice has no posted AP liability JournalEntry.';

                return [
                    'id' => $invoice->id,
                    'type' => 'held_invoice',
                    'settlement_status' => 'Held',
                    'invoice_number' => $invoice->invoice_number,
                    'vendor' => $this->vendorPayload($invoice),
                    'property' => $invoice->property?->name,
                    'currency_code' => $invoice->currency_code,
                    'amount' => (string) $invoice->grand_total,
                    'journal' => null,
                    'candidate' => null,
                    'source' => [
                        'receiving_document_id' => $invoice->goods_receipt_id,
                        'purchase_order_id' => $invoice->purchase_order_id,
                    ],
                    'reason' => $reason,
                ];
            })
            ->values()
            ->all();
    }

    private function heldCandidatePayloads(Collection $candidates, Collection $journals, Collection $invoices): array
    {
        return $candidates
            ->filter(function (JournalCandidate $candidate) use ($journals): bool {
                $journal = $journals->firstWhere('journal_candidate_id', $candidate->id);

                return $candidate->status !== JournalCandidateStatusEnum::APPROVED
                    || $journal === null
                    || $journal->status !== JournalStatusEnum::Posted;
            })
            ->map(function (JournalCandidate $candidate) use ($journals, $invoices): array {
                $journal = $journals->firstWhere('journal_candidate_id', $candidate->id);
                $invoice = $invoices->get($candidate->source_id);

                return [
                    'id' => $candidate->id,
                    'type' => 'held_candidate',
                    'settlement_status' => 'Held',
                    'invoice_number' => $invoice?->invoice_number,
                    'vendor' => $this->vendorPayload($invoice),
                    'property' => $invoice?->property?->name,
                    'currency_code' => $invoice?->currency_code ?? $candidate->metadata['currency_code'] ?? null,
                    'amount' => $candidate->metadata['amount'] ?? null,
                    'journal' => $journal ? $this->journalEvidence($journal) : null,
                    'candidate' => $this->candidateEvidence($candidate),
                    'source' => $this->sourceEvidence($candidate),
                    'reason' => $this->heldCandidateReason($candidate, $journal),
                ];
            })
            ->values()
            ->all();
    }

    private function heldCandidateReason(JournalCandidate $candidate, ?JournalEntry $journal): string
    {
        if ($candidate->status === JournalCandidateStatusEnum::REJECTED) {
            return 'GRNI/AP candidate was rejected.';
        }

        if ($candidate->status === JournalCandidateStatusEnum::PENDING_REVIEW) {
            return 'GRNI/AP candidate is pending Finance review.';
        }

        if ($journal === null) {
            return 'Approved GRNI/AP candidate has no JournalEntry Draft or posting evidence.';
        }

        if ($journal->status !== JournalStatusEnum::Posted) {
            return 'GRNI/AP JournalEntry is not posted.';
        }

        return 'Source lineage is incomplete.';
    }

    private function candidateSettlementStatus(JournalCandidate $candidate, ?JournalEntry $journal): string
    {
        if ($journal?->status === JournalStatusEnum::Posted) {
            return 'Posted AP Liability';
        }

        if ($journal?->status === JournalStatusEnum::Draft && $journal->draft_finalization_authorized_at !== null) {
            return 'Authorized Draft';
        }

        if ($journal?->status === JournalStatusEnum::Draft) {
            return 'Journal Draft';
        }

        return $candidate->status->value;
    }

    private function ageEvidence(JournalEntry $journal, ?PropertyBusinessDate $currentBusinessDate): array
    {
        $postedBusinessDate = $journal->transaction_date?->toDateString() ?? $journal->posting_date?->toDateString();
        $currentDate = $currentBusinessDate?->business_date?->toDateString();

        if (!$postedBusinessDate || !$currentDate) {
            return [
                'available' => false,
                'days' => null,
                'posted_business_date' => $postedBusinessDate,
                'current_business_date' => $currentDate,
                'label' => 'Age unavailable.',
            ];
        }

        $days = max(0, Carbon::parse($postedBusinessDate)->diffInDays(Carbon::parse($currentDate), false));

        return [
            'available' => true,
            'days' => $days,
            'posted_business_date' => $postedBusinessDate,
            'current_business_date' => $currentDate,
            'label' => $days . ' day' . ($days === 1 ? '' : 's'),
        ];
    }

    private function vendorPayload(?SupplierInvoice $invoice): ?array
    {
        if (!$invoice?->vendor) {
            return null;
        }

        return [
            'name' => $invoice->vendor->name,
            'code' => $invoice->vendor->vendor_code,
        ];
    }

    private function journalEvidence(JournalEntry $journal): array
    {
        return [
            'reference' => $journal->reference,
            'status' => $journal->status->value,
            'transaction_date' => $journal->transaction_date?->toDateString(),
            'posting_date' => $journal->posting_date?->toDateString(),
            'posted_by' => $journal->postingActor?->name,
            'posted_at' => $journal->posted_at?->toIso8601String(),
            'finalized_by' => $journal->draftFinalizationAuthorizer?->name,
            'finalized_at' => $journal->draft_finalization_authorized_at?->toIso8601String(),
        ];
    }

    private function candidateEvidence(?JournalCandidate $candidate): ?array
    {
        if (!$candidate) {
            return null;
        }

        return [
            'status' => $candidate->status->value,
            'approved_by' => $candidate->approver?->name,
            'approved_at' => $candidate->approved_at?->toIso8601String(),
            'rejected_by' => $candidate->rejector?->name,
            'rejected_at' => $candidate->rejected_at?->toIso8601String(),
            'rejection_reason' => $candidate->rejection_reason,
            'source_grni_candidate_id' => $candidate->source_grni_candidate_id,
            'source_grni_journal_entry_id' => $candidate->source_grni_journal_entry_id,
        ];
    }

    private function sourceEvidence(?JournalCandidate $candidate): array
    {
        $metadata = $candidate?->metadata ?? [];

        return [
            'purchase_order' => $metadata['purchase_order'] ?? null,
            'receiving' => $metadata['receiving'] ?? null,
            'source_grni' => $metadata['source_grni'] ?? null,
        ];
    }

    private function journalAmount(JournalEntry $journal, ?JournalCandidate $candidate): string
    {
        $debitTotal = $journal->lines->sum(fn ($line): float => (float) $line->debit_amount);
        $creditTotal = $journal->lines->sum(fn ($line): float => (float) $line->credit_amount);
        $amount = max($debitTotal, $creditTotal);

        return number_format($amount, 2, '.', '') ?: (string) ($candidate?->metadata['amount'] ?? '0.00');
    }
}
