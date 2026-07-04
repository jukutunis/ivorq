<?php

namespace Modules\Finance\FxReference\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\Payables\Models\ApSettlementAllocation;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Foundation\User\Models\User;

class FxAdjustmentControlWorkspaceProjectionService
{
    private const SOURCE_TYPE = 'ApSettlementAllocation';
    private const POSTING_EVENT = 'SupplierPaymentRealizedForeignExchange';
    private const VIEW_PERMISSION = 'finance.fx-adjustment.view';

    /**
     * Project the workspace data for the current property.
     */
    public function project(string $propertyId, string $actorId): array
    {
        $actor = $this->resolveAuthorizedActor($actorId, $propertyId);

        $pendingReview = $this->candidateBaseQuery($propertyId)
            ->where('status', JournalCandidateStatusEnum::PENDING_REVIEW->value)
            ->orderBy('candidate_date')
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        $approvedReady = $this->candidateBaseQuery($propertyId)
            ->where('status', JournalCandidateStatusEnum::APPROVED->value)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('gl_journal_entries')
                    ->whereColumn('gl_journal_entries.journal_candidate_id', 'journal_candidates.id')
                    ->whereNull('gl_journal_entries.deleted_at');
            })
            ->orderBy('approved_at')
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        $draftAwaitingAuthorization = $this->journalBaseQuery($propertyId)
            ->where('status', JournalStatusEnum::Draft->value)
            ->whereNull('draft_finalization_authorized_by')
            ->whereNull('draft_finalization_authorized_at')
            ->whereNull('posting_date')
            ->orderBy('transaction_date')
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        $authorizedReadyToPost = $this->journalBaseQuery($propertyId)
            ->where('status', JournalStatusEnum::Draft->value)
            ->whereNotNull('draft_finalization_authorized_by')
            ->whereNotNull('draft_finalization_authorized_at')
            ->whereNull('posting_date')
            ->orderBy('draft_finalization_authorized_at')
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        $postedHistory = $this->journalBaseQuery($propertyId)
            ->where('status', JournalStatusEnum::Posted->value)
            ->orderByDesc('posted_at')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        $allocationEvidence = $this->allocationEvidenceFor([
            ...$pendingReview->pluck('source_id')->all(),
            ...$approvedReady->pluck('source_id')->all(),
            ...$draftAwaitingAuthorization->pluck('source_id')->all(),
            ...$authorizedReadyToPost->pluck('source_id')->all(),
            ...$postedHistory->pluck('source_id')->all(),
        ], $propertyId);

        return [
            'pending_review' => $pendingReview
                ->map(fn (JournalCandidate $c) => $this->candidatePayload($c, $allocationEvidence))
                ->values()
                ->all(),
            'approved_ready' => $approvedReady
                ->map(fn (JournalCandidate $c) => $this->candidatePayload($c, $allocationEvidence))
                ->values()
                ->all(),
            'draft_awaiting_authorization' => $draftAwaitingAuthorization
                ->map(fn (JournalEntry $j) => $this->journalPayload($j, $allocationEvidence))
                ->values()
                ->all(),
            'authorized_ready_to_post' => $authorizedReadyToPost
                ->map(fn (JournalEntry $j) => $this->journalPayload($j, $allocationEvidence))
                ->values()
                ->all(),
            'posted_history' => $postedHistory
                ->map(fn (JournalEntry $j) => $this->journalPayload($j, $allocationEvidence))
                ->values()
                ->all(),
        ];
    }

    private function candidateBaseQuery(string $propertyId): Builder
    {
        return JournalCandidate::with([
            'lines',
            'approver:id,name',
            'creator:id,name',
            'rejector:id,name',
        ])
            ->where('property_id', $propertyId)
            ->where('source_type', self::SOURCE_TYPE)
            ->where('posting_event', self::POSTING_EVENT);
    }

    private function journalBaseQuery(string $propertyId): Builder
    {
        return JournalEntry::with([
            'lines.account',
            'candidate.approver:id,name',
            'candidate.lines',
            'draftFinalizationAuthorizer:id,name',
            'postingActor:id,name',
        ])
            ->where('property_id', $propertyId)
            ->where('source_type', self::SOURCE_TYPE)
            ->where('posting_event', self::POSTING_EVENT)
            ->whereNotNull('journal_candidate_id');
    }

    private function allocationEvidenceFor(array $allocationIds, string $propertyId): array
    {
        $ids = collect($allocationIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $allocations = ApSettlementAllocation::with([
            'apJournalEntry',
            'paymentJournalEntry',
            'paymentExecution',
            'allocator:id,name',
        ])
            ->where('property_id', $propertyId)
            ->whereIn('id', $ids)
            ->get();

        // Batch-load supplier invoices by supplier_invoice_id directly (PaymentExecution has no supplierInvoice relation)
        $invoiceIds = $allocations
            ->map(fn (ApSettlementAllocation $alloc) => $alloc->paymentExecution?->supplier_invoice_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $invoicesById = SupplierInvoice::whereIn('id', $invoiceIds)
            ->get()
            ->keyBy('id');

        return $allocations
            ->mapWithKeys(function (ApSettlementAllocation $alloc) use ($invoicesById) {
                $execution = $alloc->paymentExecution;
                $invoice = $execution?->supplier_invoice_id
                    ? ($invoicesById[$execution->supplier_invoice_id] ?? null)
                    : null;

                return [
                    $alloc->id => [
                        'allocation_id' => $alloc->id,
                        'allocation_amount' => (float)$alloc->allocation_amount,
                        'currency' => $alloc->currency_code,
                        'allocated_by' => $alloc->allocator?->name,
                        'allocated_at' => $alloc->allocated_at?->toIso8601String(),
                        'invoice_number' => $invoice?->invoice_number,
                        'invoice_grand_total' => $invoice ? (float)$invoice->grand_total : null,
                        'payment_execution_id' => $execution?->id,
                        'payment_ref' => $alloc->paymentJournalEntry?->reference,
                        'payment_date' => $execution?->executed_at?->toIso8601String(),
                        'ap_journal_entry_id' => $alloc->ap_journal_entry_id,
                        'ap_journal_reference' => $alloc->apJournalEntry?->reference,
                        'payment_journal_entry_id' => $alloc->payment_journal_entry_id,
                        'payment_journal_reference' => $alloc->paymentJournalEntry?->reference,
                    ]
                ];
            })
            ->all();
    }


    private function candidatePayload(JournalCandidate $candidate, array $allocationEvidence): array
    {
        $lines = $candidate->lines
            ->sortBy('id')
            ->map(function ($line) {
                return [
                    'id' => $line->id,
                    'identity' => $line->operational_identity?->value,
                    'entry_type' => $line->entry_type?->value,
                    'amount' => (float)$line->amount,
                    'notes' => $line->notes,
                ];
            })
            ->values()
            ->all();

        $debitTotal = collect($lines)->where('entry_type', EntryTypeEnum::DEBIT->value)->sum('amount');
        $creditTotal = collect($lines)->where('entry_type', EntryTypeEnum::CREDIT->value)->sum('amount');

        $metadata = $candidate->metadata ?? [];
        $candidateLines = $candidate?->lines
            ? $candidate->lines
                ->map(fn ($line) => [
                    'identity' => $line->operational_identity?->value,
                    'entry_type' => $line->entry_type?->value,
                ])
                ->all()
            : $lines;
        $rateEvidence = $this->rateEvidencePayload($metadata);
        $mappingSummary = $this->mappingSummaryPayload($metadata);

        return [
            'type' => 'candidate',
            'id' => $candidate->id,
            'source_type' => $candidate->source_type,
            'source_id' => $candidate->source_id,
            'source' => $allocationEvidence[$candidate->source_id] ?? null,
            'posting_event' => $candidate->posting_event,
            'status' => $candidate->status?->value,
            'candidate_date' => $candidate->candidate_date?->toDateString(),
            'description' => $candidate->description,
            'approved_by' => $candidate->approver?->name,
            'approved_at' => $candidate->approved_at?->toIso8601String(),
            'created_by' => $candidate->creator?->name,
            'rejected_by' => $candidate->rejector?->name,
            'rejected_at' => $candidate->rejected_at?->toIso8601String(),
            'rejection_reason' => $candidate->rejection_reason,
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'amount' => max($debitTotal, $creditTotal),
            'realized_direction' => $this->realizedDirection($candidateLines),
            'rate' => $rateEvidence['rate'] ?? null,
            'rate_evidence' => $rateEvidence,
            'mapping_summary' => $mappingSummary,
            'lines' => $lines,
        ];
    }

    private function journalPayload(JournalEntry $journal, array $allocationEvidence): array
    {
        $lines = $journal->lines
            ->sortBy('id')
            ->map(function ($line) {
                return [
                    'id' => $line->id,
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'debit_amount' => (float)$line->debit_amount,
                    'credit_amount' => (float)$line->credit_amount,
                    'memo' => $line->memo,
                ];
            })
            ->values()
            ->all();

        $debitTotal = collect($lines)->sum('debit_amount');
        $creditTotal = collect($lines)->sum('credit_amount');

        $candidate = $journal->candidate;
        $metadata = $candidate?->metadata ?? [];
        $rateEvidence = $this->rateEvidencePayload($metadata);
        $mappingSummary = $this->mappingSummaryPayload($metadata);

        return [
            'type' => 'journal',
            'id' => $journal->id,
            'candidate_id' => $journal->journal_candidate_id,
            'source_type' => $journal->source_type,
            'source_id' => $journal->source_id,
            'source' => $allocationEvidence[$journal->source_id] ?? null,
            'posting_event' => $journal->posting_event,
            'reference' => $journal->reference,
            'description' => $journal->description,
            'status' => $journal->status?->value,
            'transaction_date' => $journal->transaction_date?->toDateString(),
            'posting_date' => $journal->posting_date?->toDateString(),
            'approved_by' => $candidate?->approver?->name,
            'approved_at' => $candidate?->approved_at?->toIso8601String(),
            'draft_finalization_authorized_by' => $journal->draftFinalizationAuthorizer?->name,
            'draft_finalization_authorized_at' => $journal->draft_finalization_authorized_at?->toIso8601String(),
            'posted_by' => $journal->postingActor?->name,
            'posted_at' => $journal->posted_at?->toIso8601String(),
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'amount' => max($debitTotal, $creditTotal),
            'realized_direction' => $this->realizedDirection($lines),
            'rate' => $rateEvidence['rate'] ?? null,
            'rate_evidence' => $rateEvidence,
            'mapping_summary' => $mappingSummary,
            'lines' => $lines,
        ];
    }

    private function realizedDirection(array $lines): string
    {
        $hasGain = collect($lines)->contains(
            fn (array $line) => ($line['identity'] ?? null) === 'FX_GAIN'
                && ($line['entry_type'] ?? null) === EntryTypeEnum::CREDIT->value
        );

        if ($hasGain) {
            return 'GAIN';
        }

        $hasLoss = collect($lines)->contains(
            fn (array $line) => ($line['identity'] ?? null) === 'FX_LOSS'
                && ($line['entry_type'] ?? null) === EntryTypeEnum::DEBIT->value
        );

        return $hasLoss ? 'LOSS' : 'RECORDED';
    }

    private function rateEvidencePayload(array $metadata): ?array
    {
        $snapshot = $metadata['exchange_rate_evidence_snapshot'] ?? null;

        if (!is_array($snapshot)) {
            return null;
        }

        return [
            'id' => $snapshot['id'] ?? null,
            'base_currency' => $snapshot['base_currency'] ?? null,
            'quote_currency' => $snapshot['quote_currency'] ?? null,
            'rate' => $snapshot['rate'] ?? null,
            'effective_date' => $snapshot['effective_date'] ?? null,
            'status' => $snapshot['status'] ?? null,
        ];
    }

    private function mappingSummaryPayload(array $metadata): array
    {
        return [
            'fx_gain' => $this->mappingSnapshotPayload($metadata['fx_gain_mapping_snapshot'] ?? null),
            'fx_loss' => $this->mappingSnapshotPayload($metadata['fx_loss_mapping_snapshot'] ?? null),
        ];
    }

    private function mappingSnapshotPayload(mixed $snapshot): ?array
    {
        if (!is_array($snapshot)) {
            return null;
        }

        return [
            'id' => $snapshot['id'] ?? null,
            'operational_identity' => $snapshot['operational_identity'] ?? null,
            'account_id' => $snapshot['account_id'] ?? null,
            'is_active' => $snapshot['is_active'] ?? null,
        ];
    }

    private function resolveAuthorizedActor(string $actorId, string $propertyId): User
    {
        $actor = User::where('id', $actorId)
            ->where('is_active', true)
            ->first();

        if (!$actor) {
            throw new AuthorizationException('Unauthorized to view workspace.');
        }

        $hasPropertyAccess = $actor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('Unauthorized to view workspace.');
        }

        if (!$actor->can(self::VIEW_PERMISSION)) {
            throw new AuthorizationException('Unauthorized to view workspace.');
        }

        return $actor;
    }
}
