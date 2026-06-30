<?php

namespace Modules\Finance\Payables\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\Payables\Enums\PaymentProposalStatusEnum;
use Modules\Finance\Payables\Models\PaymentProposal;
use Modules\Finance\Payables\Services\ApGrniSettlementAgingProjectionService;
use Modules\Finance\Payables\Services\PaymentProposalService;
use Modules\Finance\Payables\Services\SupplierInvoiceApprovalService;
use Modules\Finance\Payables\Services\SupplierInvoiceExceptionReviewService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Throwable;

class ApGrniSettlementControlWorkspaceController extends Controller
{
    private const VIEW_PERMISSIONS = [
        SupplierInvoiceApprovalService::PERMISSION,
        SupplierInvoiceExceptionReviewService::PERMISSION,
        'finance.payables.grni-clearing.candidate.create',
        PaymentProposalService::CREATE_PERMISSION,
        PaymentProposalService::CANCEL_PERMISSION,
    ];

    public function __construct(
        private readonly ApGrniSettlementAgingProjectionService $projectionService,
        private readonly PaymentProposalService $paymentProposalService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $this->authorizeWorkspaceAccess($user);
        $propertyId = $this->resolvePropertyId($request);

        return Inertia::render('Ivorq/Finance/ApGrniSettlementControlWorkspace', [
            'projection' => $this->projectionService->project($propertyId),
            'payment_proposals' => $this->draftProposalPayloads($propertyId),
            'permissions' => [
                'can_view' => true,
                'can_create_payment_proposal' => $user->can(PaymentProposalService::CREATE_PERMISSION),
                'can_cancel_payment_proposal' => $user->can(PaymentProposalService::CANCEL_PERMISSION),
            ],
        ]);
    }

    public function createDraft(Request $request): RedirectResponse
    {
        $this->authorizeAction($request->user(), PaymentProposalService::CREATE_PERMISSION);
        $propertyId = $this->resolvePropertyId($request);

        $validated = $request->validate([
            'journal_entry_ids' => ['required', 'array', 'min:1', 'max:25'],
            'journal_entry_ids.*' => ['required', 'string', 'size:26'],
        ]);

        return $this->redirectingAction(
            fn () => $this->paymentProposalService->createDraft($validated['journal_entry_ids'], $request->user()),
            'Payment Proposal Draft created.',
            $propertyId
        );
    }

    public function cancelDraft(Request $request, string $paymentProposal): RedirectResponse
    {
        $this->authorizeAction($request->user(), PaymentProposalService::CANCEL_PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        $this->findScopedDraftProposal($paymentProposal, $propertyId);

        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->redirectingAction(
            fn () => $this->paymentProposalService->cancelDraft($paymentProposal, $request->user(), $validated['cancellation_reason']),
            'Payment Proposal Draft cancelled.',
            $propertyId
        );
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

    private function findScopedDraftProposal(string $proposalId, string $propertyId): PaymentProposal
    {
        return PaymentProposal::where('property_id', $propertyId)
            ->where('status', PaymentProposalStatusEnum::DRAFT->value)
            ->findOrFail($proposalId);
    }

    private function draftProposalPayloads(string $propertyId): array
    {
        return PaymentProposal::with(['vendor', 'items.supplierInvoice'])
            ->where('property_id', $propertyId)
            ->where('status', PaymentProposalStatusEnum::DRAFT->value)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (PaymentProposal $proposal): array => [
                'id' => $proposal->id,
                'proposal_number' => $proposal->proposal_number,
                'vendor' => $proposal->vendor?->name,
                'currency_code' => $proposal->currency_code,
                'status' => $proposal->status->value,
                'total_amount' => (string) $proposal->total_amount,
                'created_by' => $proposal->created_by,
                'created_at' => $proposal->created_at?->toIso8601String(),
                'items' => $proposal->items
                    ->where('is_active', true)
                    ->map(fn ($item): array => [
                        'id' => $item->id,
                        'invoice_number' => $item->supplierInvoice?->invoice_number,
                        'source_journal_entry_id' => $item->source_journal_entry_id,
                        'source_amount' => (string) $item->source_amount,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function redirectingAction(callable $action, string $successMessage, string $propertyId): RedirectResponse
    {
        try {
            $action();

            return redirect()
                ->route('finance.payables.ap-grni-settlement-control')
                ->with('success', $successMessage);
        } catch (Throwable $exception) {
            return redirect()
                ->route('finance.payables.ap-grni-settlement-control')
                ->with('error', $exception->getMessage());
        }
    }
}
