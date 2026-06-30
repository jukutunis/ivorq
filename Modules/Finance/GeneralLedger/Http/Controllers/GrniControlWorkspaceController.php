<?php

namespace Modules\Finance\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Services\JournalCandidateDraftMaterializationService;
use Modules\Finance\GeneralLedger\Services\JournalCandidateReviewService;
use Modules\Finance\GeneralLedger\Services\JournalEntryControlledPostingService;
use Modules\Finance\GeneralLedger\Services\JournalEntryDraftFinalizationAuthorizationService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Shared\Services\CurrentPropertyService;
use Throwable;

class GrniControlWorkspaceController extends Controller
{
    private const SOURCE_TYPE = 'InventoryReceipt';
    private const POSTING_EVENT = 'InventoryReceiptAccrual';

    private const WORKSPACE_ROUTE = 'finance.general-ledger.grni-control';

    private const VIEW_PERMISSIONS = [
        'finance.journal-candidate.review',
        JournalCandidateDraftMaterializationService::PERMISSION,
        JournalEntryDraftFinalizationAuthorizationService::PERMISSION,
        JournalEntryControlledPostingService::PERMISSION,
    ];

    public function __construct(
        private readonly JournalCandidateReviewService $reviewService,
        private readonly JournalCandidateDraftMaterializationService $materializationService,
        private readonly JournalEntryDraftFinalizationAuthorizationService $authorizationService,
        private readonly JournalEntryControlledPostingService $postingService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $this->authorizeWorkspaceAccess($user);
        $propertyId = $this->resolvePropertyId($request);

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

        $receiptEvidence = $this->receiptEvidenceFor([
            ...$pendingReview->pluck('source_id')->all(),
            ...$approvedReady->pluck('source_id')->all(),
            ...$draftAwaitingAuthorization->pluck('source_id')->all(),
            ...$authorizedReadyToPost->pluck('source_id')->all(),
            ...$postedHistory->pluck('source_id')->all(),
        ], $propertyId);

        return Inertia::render('Ivorq/Finance/GrniControlWorkspace', [
            'queues' => [
                'pending_review' => $pendingReview
                    ->map(fn (JournalCandidate $candidate) => $this->candidatePayload($candidate, $receiptEvidence))
                    ->values(),
                'approved_ready' => $approvedReady
                    ->map(fn (JournalCandidate $candidate) => $this->candidatePayload($candidate, $receiptEvidence))
                    ->values(),
                'draft_awaiting_authorization' => $draftAwaitingAuthorization
                    ->map(fn (JournalEntry $journal) => $this->journalPayload($journal, $receiptEvidence))
                    ->values(),
                'authorized_ready_to_post' => $authorizedReadyToPost
                    ->map(fn (JournalEntry $journal) => $this->journalPayload($journal, $receiptEvidence))
                    ->values(),
                'posted_history' => $postedHistory
                    ->map(fn (JournalEntry $journal) => $this->journalPayload($journal, $receiptEvidence))
                    ->values(),
            ],
            'permissions' => [
                'can_review' => $user->can('finance.journal-candidate.review'),
                'can_materialize' => $user->can(JournalCandidateDraftMaterializationService::PERMISSION),
                'can_authorize' => $user->can(JournalEntryDraftFinalizationAuthorizationService::PERMISSION),
                'can_post' => $user->can(JournalEntryControlledPostingService::PERMISSION),
            ],
        ]);
    }

    public function approve(Request $request, string $candidate): RedirectResponse
    {
        $this->authorizeAction($request->user(), 'finance.journal-candidate.review');
        $propertyId = $this->resolvePropertyId($request);
        $this->findScopedCandidate($candidate, $propertyId);

        return $this->redirectingAction(
            fn () => $this->reviewService->approve($candidate, $request->user()->id),
            'GRNI candidate approved.'
        );
    }

    public function reject(Request $request, string $candidate): RedirectResponse
    {
        $this->authorizeAction($request->user(), 'finance.journal-candidate.review');
        $propertyId = $this->resolvePropertyId($request);
        $this->findScopedCandidate($candidate, $propertyId);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->redirectingAction(
            fn () => $this->reviewService->reject($candidate, $validated['rejection_reason'], $request->user()->id),
            'GRNI candidate rejected.'
        );
    }

    public function materialize(Request $request, string $candidate): RedirectResponse
    {
        $this->authorizeAction($request->user(), JournalCandidateDraftMaterializationService::PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        $this->findScopedCandidate($candidate, $propertyId);

        return $this->redirectingAction(
            fn () => $this->materializationService->materialize($candidate, $request->user()->id),
            'GRNI journal draft created.'
        );
    }

    public function authorizeFinalization(Request $request, string $journalEntry): RedirectResponse
    {
        $this->authorizeAction($request->user(), JournalEntryDraftFinalizationAuthorizationService::PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        $this->findScopedJournal($journalEntry, $propertyId);

        return $this->redirectingAction(
            fn () => $this->authorizationService->authorize($journalEntry, $request->user()->id),
            'GRNI journal draft authorized.'
        );
    }

    public function post(Request $request, string $journalEntry): RedirectResponse
    {
        $this->authorizeAction($request->user(), JournalEntryControlledPostingService::PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        $this->findScopedJournal($journalEntry, $propertyId);

        return $this->redirectingAction(
            fn () => $this->postingService->post($journalEntry, $request->user()->id),
            'GRNI journal posted.'
        );
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
            'draftFinalizationAuthorizer:id,name',
            'postingActor:id,name',
        ])
            ->where('property_id', $propertyId)
            ->where('source_module', 'Inventory')
            ->where('source_type', self::SOURCE_TYPE)
            ->where('posting_event', self::POSTING_EVENT)
            ->whereNotNull('journal_candidate_id');
    }

    private function receiptEvidenceFor(array $sourceIds, string $propertyId): array
    {
        $ids = collect($sourceIds)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return InventoryReceipt::with([
            'receivingDocument.vendor',
            'receivingDocument.purchaseOrder',
        ])
            ->where('property_id', $propertyId)
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(function (InventoryReceipt $receipt) {
                $receivingDocument = $receipt->receivingDocument;

                return [
                    $receipt->id => [
                        'receipt_number' => $receipt->receipt_number,
                        'supplier_name' => $receipt->supplier_name,
                        'external_reference' => $receipt->external_reference,
                        'grn_number' => $receivingDocument?->grn_number,
                        'vendor_delivery_no' => $receivingDocument?->vendor_delivery_no,
                        'vendor_name' => $receivingDocument?->vendor?->name,
                        'vendor_code' => $receivingDocument?->vendor?->vendor_code,
                        'po_no' => $receivingDocument?->purchaseOrder?->po_no,
                    ],
                ];
            })
            ->all();
    }

    private function candidatePayload(JournalCandidate $candidate, array $receiptEvidence): array
    {
        $lines = $candidate->lines
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->map(function ($line) {
                return [
                    'id' => $line->id,
                    'identity' => self::enumValue($line->operational_identity),
                    'entry_type' => self::enumValue($line->entry_type),
                    'amount' => (float) $line->amount,
                    'notes' => $line->notes,
                ];
            })
            ->values();

        $debitTotal = $lines
            ->where('entry_type', EntryTypeEnum::DEBIT->value)
            ->sum('amount');
        $creditTotal = $lines
            ->where('entry_type', EntryTypeEnum::CREDIT->value)
            ->sum('amount');

        return [
            'type' => 'candidate',
            'id' => $candidate->id,
            'source_type' => $candidate->source_type,
            'source_id' => $candidate->source_id,
            'source' => $receiptEvidence[$candidate->source_id] ?? null,
            'posting_event' => $candidate->posting_event,
            'status' => self::enumValue($candidate->status),
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
            'lines' => $lines,
        ];
    }

    private function journalPayload(JournalEntry $journal, array $receiptEvidence): array
    {
        $lines = $journal->lines
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->map(function ($line) {
                return [
                    'id' => $line->id,
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'debit_amount' => (float) $line->debit_amount,
                    'credit_amount' => (float) $line->credit_amount,
                    'memo' => $line->memo,
                ];
            })
            ->values();

        $debitTotal = $lines->sum('debit_amount');
        $creditTotal = $lines->sum('credit_amount');

        return [
            'type' => 'journal',
            'id' => $journal->id,
            'candidate_id' => $journal->journal_candidate_id,
            'source_type' => $journal->source_type,
            'source_id' => $journal->source_id,
            'source' => $receiptEvidence[$journal->source_id] ?? null,
            'posting_event' => $journal->posting_event,
            'reference' => $journal->reference,
            'description' => $journal->description,
            'status' => self::enumValue($journal->status),
            'transaction_date' => $journal->transaction_date?->toDateString(),
            'posting_date' => $journal->posting_date?->toDateString(),
            'approved_by' => $journal->candidate?->approver?->name,
            'approved_at' => $journal->candidate?->approved_at?->toIso8601String(),
            'draft_finalization_authorized_by' => $journal->draftFinalizationAuthorizer?->name,
            'draft_finalization_authorized_at' => $journal->draft_finalization_authorized_at?->toIso8601String(),
            'posted_by' => $journal->postingActor?->name,
            'posted_at' => $journal->posted_at?->toIso8601String(),
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'amount' => max($debitTotal, $creditTotal),
            'lines' => $lines,
        ];
    }

    private function findScopedCandidate(string $candidateId, string $propertyId): JournalCandidate
    {
        return JournalCandidate::where('property_id', $propertyId)
            ->where('source_type', self::SOURCE_TYPE)
            ->where('posting_event', self::POSTING_EVENT)
            ->findOrFail($candidateId);
    }

    private function findScopedJournal(string $journalEntryId, string $propertyId): JournalEntry
    {
        return JournalEntry::where('property_id', $propertyId)
            ->where('source_module', 'Inventory')
            ->where('source_type', self::SOURCE_TYPE)
            ->where('posting_event', self::POSTING_EVENT)
            ->whereNotNull('journal_candidate_id')
            ->findOrFail($journalEntryId);
    }

    private function redirectingAction(callable $action, string $successMessage): RedirectResponse
    {
        try {
            $action();

            return redirect()
                ->route(self::WORKSPACE_ROUTE)
                ->with('success', $successMessage);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return redirect()
                ->route(self::WORKSPACE_ROUTE)
                ->with('error', $exception->getMessage());
        }
    }

    private function resolvePropertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();

        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }

    private function authorizeWorkspaceAccess(?User $user): void
    {
        if (!$user) {
            abort(403, 'Unauthorized.');
        }

        foreach (self::VIEW_PERMISSIONS as $permission) {
            if ($user->can($permission)) {
                return;
            }
        }

        abort(403, 'Unauthorized.');
    }

    private function authorizeAction(?User $user, string $permission): void
    {
        if (!$user || !$user->can($permission)) {
            abort(403, 'Unauthorized.');
        }
    }

    private static function enumValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}
