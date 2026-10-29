<?php

namespace Modules\Finance\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class GeneralLedgerOperationsWorkspaceController extends Controller
{
    private const VIEW_PERMISSIONS = [
        'finance.journal-candidate.review',
        'finance.journal-candidate.materialize-draft',
        'finance.journal-entry-draft.authorize-finalization',
        'finance.journal-entry.post',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->authorizeWorkspace($user);
        $propertyId = $this->propertyId($request);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $candidates = JournalCandidate::query()
            ->with(['lines', 'creator:id,name', 'approver:id,name', 'rejector:id,name'])
            ->where('property_id', $propertyId)
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(function ($nested) use ($search): void {
                $nested->where('source_id', 'ilike', "%{$search}%")
                    ->orWhere('source_type', 'ilike', "%{$search}%")
                    ->orWhere('posting_event', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            }))
            ->when($filters['state'] ?? null, fn ($query, $state) => $query->where('status', $state))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('candidate_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('candidate_date', '<=', $to))
            ->latest('created_at')
            ->limit(100)
            ->get();

        $journals = JournalEntry::query()
            ->with(['lines.account:id,code,name', 'candidate:id,status,approved_by,approved_at', 'postingActor:id,name', 'draftFinalizationAuthorizer:id,name'])
            ->where('property_id', $propertyId)
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(function ($nested) use ($search): void {
                $nested->where('reference', 'ilike', "%{$search}%")
                    ->orWhere('source_id', 'ilike', "%{$search}%")
                    ->orWhere('source_type', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            }))
            ->when($filters['state'] ?? null, fn ($query, $state) => $query->where('status', $state))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('transaction_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('transaction_date', '<=', $to))
            ->latest('created_at')
            ->limit(100)
            ->get();

        $reversedBy = JournalEntry::query()
            ->where('property_id', $propertyId)
            ->whereIn('reversal_of_id', $journals->pluck('id'))
            ->pluck('id', 'reversal_of_id');

        return Inertia::render('Ivorq/Finance/GeneralLedgerOperationsWorkspace', [
            'filters' => $filters,
            'candidates' => $candidates->map(fn (JournalCandidate $candidate) => [
                'id' => $candidate->id,
                'source_type' => $candidate->source_type,
                'source_id' => $candidate->source_id,
                'posting_event' => $candidate->posting_event,
                'status' => self::value($candidate->status),
                'candidate_date' => $candidate->candidate_date?->toDateString(),
                'description' => $candidate->description,
                'created_by' => $candidate->creator?->name,
                'approved_by' => $candidate->approver?->name,
                'rejected_by' => $candidate->rejector?->name,
                'line_count' => $candidate->lines->count(),
            ])->values(),
            'journals' => $journals->map(fn (JournalEntry $journal) => [
                'id' => $journal->id,
                'reference' => $journal->reference,
                'source_module' => $journal->source_module,
                'source_type' => $journal->source_type,
                'source_id' => $journal->source_id,
                'posting_event' => $journal->posting_event,
                'status' => self::value($journal->status),
                'transaction_date' => $journal->transaction_date?->toDateString(),
                'posting_date' => $journal->posting_date?->toDateString(),
                'description' => $journal->description,
                'candidate_id' => $journal->journal_candidate_id,
                'reversal_of_id' => $journal->reversal_of_id,
                'reversed_by_id' => $reversedBy[$journal->id] ?? null,
                'finalized_by' => $journal->draftFinalizationAuthorizer?->name,
                'posted_by' => $journal->postingActor?->name,
                'posted_at' => $journal->posted_at?->toIso8601String(),
                'lines' => $journal->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'debit_amount' => (string) $line->debit_amount,
                    'credit_amount' => (string) $line->credit_amount,
                    'memo' => $line->memo,
                ])->values(),
            ])->values(),
            'permissions' => collect(self::VIEW_PERMISSIONS)->mapWithKeys(fn ($permission) => [$permission => $user->can($permission)]),
            'boundaries' => [
                'mutation_mode' => 'SPECIALIZED_WORKSPACES_ONLY',
                'grni_route' => route('finance.general-ledger.grni-control'),
                'realized_fx_route' => route('finance.fx-adjustments.index'),
            ],
        ]);
    }

    private function propertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();
        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }

    private function authorizeWorkspace(?User $user): void
    {
        if ($user && collect(self::VIEW_PERMISSIONS)->contains(fn ($permission) => $user->can($permission))) {
            return;
        }

        abort(403, 'Unauthorized.');
    }

    private static function value(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : ($value === null ? null : (string) $value);
    }
}
