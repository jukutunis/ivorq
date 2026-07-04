<?php

namespace Modules\Finance\FxReference\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\FxReference\Services\FxAdjustmentControlWorkspaceProjectionService;
use Modules\Finance\FxReference\Services\FxBreakGlassAccessService;
use Modules\Finance\FxReference\Services\RealizedFxAdjustmentCandidateService;
use Modules\Finance\FxReference\Services\RealizedFxAdjustmentCandidateReviewService;
use Modules\Finance\FxReference\Services\RealizedFxAdjustmentDraftMaterializationService;
use Modules\Finance\FxReference\Services\RealizedFxAdjustmentFinalizationAuthorizationService;
use Modules\Finance\FxReference\Services\RealizedFxAdjustmentPostingService;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\Payables\Models\ApSettlementAllocation;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Throwable;

class FxAdjustmentControlWorkspaceController extends Controller
{
    private const WORKSPACE_ROUTE = 'finance.fx-adjustments.index';
    private const VIEW_PERMISSION = 'finance.fx-adjustment.view';

    public function __construct(
        private readonly FxAdjustmentControlWorkspaceProjectionService $projectionService,
        private readonly RealizedFxAdjustmentCandidateService $candidateService,
        private readonly RealizedFxAdjustmentCandidateReviewService $reviewService,
        private readonly RealizedFxAdjustmentDraftMaterializationService $materializationService,
        private readonly RealizedFxAdjustmentFinalizationAuthorizationService $authorizationService,
        private readonly RealizedFxAdjustmentPostingService $postingService,
        private readonly FxBreakGlassAccessService $breakGlassService,
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $propertyId = $this->resolvePropertyId($request);
        $companyId = $request->session()->get('active_company_id');
        $this->authorizeAction($user, self::VIEW_PERMISSION, $propertyId);

        $this->guardBreakGlass($user, $propertyId, $companyId);

        $queues = $this->projectionService->project($propertyId, $user->id);

        return Inertia::render('Ivorq/Finance/FxAdjustmentControlWorkspace', [
            'queues' => $queues,
            'permissions' => [
                'can_create' => $user->can(RealizedFxAdjustmentCandidateService::PERMISSION),
                'can_review' => $user->can(RealizedFxAdjustmentCandidateReviewService::PERMISSION),
                'can_materialize' => $user->can(RealizedFxAdjustmentDraftMaterializationService::PERMISSION),
                'can_authorize' => $user->can(RealizedFxAdjustmentFinalizationAuthorizationService::PERMISSION),
                'can_post' => $user->can(RealizedFxAdjustmentPostingService::PERMISSION),
            ],
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $propertyId = $this->resolvePropertyId($request);
        $companyId = $request->session()->get('active_company_id');
        $this->authorizeAction($request->user(), RealizedFxAdjustmentCandidateService::PERMISSION, $propertyId);
        $this->guardBreakGlass($request->user(), $propertyId, $companyId);

        $validated = $request->validate([
            'allocation_id' => ['required', 'string', 'ulid'],
        ]);

        $allocationId = $validated['allocation_id'];
        $this->findScopedAllocation($allocationId, $propertyId);

        return $this->redirectingAction(
            fn () => $this->candidateService->create($allocationId, $request->user()),
            'Realized FX candidate created.'
        );
    }

    public function review(Request $request, string $candidate): RedirectResponse
    {
        $propertyId = $this->resolvePropertyId($request);
        $companyId = $request->session()->get('active_company_id');
        $this->authorizeAction($request->user(), RealizedFxAdjustmentCandidateReviewService::PERMISSION, $propertyId);
        $this->guardBreakGlass($request->user(), $propertyId, $companyId);
        $this->findScopedCandidate($candidate, $propertyId);

        if (!$this->confirmationService->hasValidConfirmation($request->user(), 'finance-approval', $companyId, $propertyId)) {
            return redirect()
                ->route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval'])
                ->with('error', 'Sensitive action confirmation is required before approving or rejecting Finance documents.');
        }

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:APPROVED,REJECTED'],
            'rejection_reason' => ['required_if:decision,REJECTED', 'nullable', 'string', 'min:3', 'max:500'],
        ]);

        return $this->redirectingAction(
            function () use ($candidate, $validated, $request) {
                if ($validated['decision'] === 'APPROVED') {
                    $this->reviewService->approve($candidate, $request->user()->id);
                } else {
                    $this->reviewService->reject($candidate, $validated['rejection_reason'], $request->user()->id);
                }
            },
            'Realized FX candidate review recorded.'
        );
    }

    public function materialize(Request $request, string $candidate): RedirectResponse
    {
        $propertyId = $this->resolvePropertyId($request);
        $companyId = $request->session()->get('active_company_id');
        $this->authorizeAction($request->user(), RealizedFxAdjustmentDraftMaterializationService::PERMISSION, $propertyId);
        $this->guardBreakGlass($request->user(), $propertyId, $companyId);
        $this->findScopedCandidate($candidate, $propertyId);

        return $this->redirectingAction(
            fn () => $this->materializationService->materialize($candidate, $request->user()->id),
            'Realized FX journal draft created.'
        );
    }

    public function authorizeFinalization(Request $request, string $journalEntry): RedirectResponse
    {
        $propertyId = $this->resolvePropertyId($request);
        $companyId = $request->session()->get('active_company_id');
        $this->authorizeAction($request->user(), RealizedFxAdjustmentFinalizationAuthorizationService::PERMISSION, $propertyId);
        $this->guardBreakGlass($request->user(), $propertyId, $companyId);
        $this->findScopedJournal($journalEntry, $propertyId);

        return $this->redirectingAction(
            fn () => $this->authorizationService->authorize($journalEntry, $request->user()->id),
            'Realized FX journal draft authorized.'
        );
    }

    public function post(Request $request, string $journalEntry): RedirectResponse
    {
        $propertyId = $this->resolvePropertyId($request);
        $companyId = $request->session()->get('active_company_id');
        $this->authorizeAction($request->user(), RealizedFxAdjustmentPostingService::PERMISSION, $propertyId);
        $this->guardBreakGlass($request->user(), $propertyId, $companyId);
        $this->findScopedJournal($journalEntry, $propertyId);

        return $this->redirectingAction(
            fn () => $this->postingService->post($journalEntry, $request->user()->id),
            'Realized FX journal posted.'
        );
    }

    private function findScopedAllocation(string $allocationId, string $propertyId): void
    {
        ApSettlementAllocation::where('property_id', $propertyId)
            ->findOrFail($allocationId);
    }

    private function findScopedCandidate(string $candidateId, string $propertyId): void
    {
        JournalCandidate::where('property_id', $propertyId)
            ->where('source_type', 'ApSettlementAllocation')
            ->where('posting_event', 'SupplierPaymentRealizedForeignExchange')
            ->findOrFail($candidateId);
    }

    private function findScopedJournal(string $journalEntryId, string $propertyId): void
    {
        JournalEntry::where('property_id', $propertyId)
            ->where('source_type', 'ApSettlementAllocation')
            ->where('posting_event', 'SupplierPaymentRealizedForeignExchange')
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
        } catch (DomainException $exception) {
            return redirect()
                ->route(self::WORKSPACE_ROUTE)
                ->with('error', $exception->getMessage());
        } catch (Throwable) {
            return redirect()
                ->route(self::WORKSPACE_ROUTE)
                ->with('error', 'The realized FX action could not be completed. Review the item state and actor authority.');
        }
    }

    private function resolvePropertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();

        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }

    private function authorizeAction(?User $user, string $permission, string $propertyId): void
    {
        if (!$user) {
            abort(403, 'Unauthorized.');
        }

        $hasPropertyAccess = $user->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            abort(403, 'Unauthorized.');
        }

        if (!$user->can($permission)) {
            abort(403, 'Unauthorized.');
        }
    }

    private function guardBreakGlass(User $user, string $propertyId, ?string $companyId): void
    {
        try {
            $this->breakGlassService->requireOperationalFxAccess($user, $propertyId, $companyId);
        } catch (DomainException $exception) {
            abort(redirect()
                ->route('finance.fx-break-glass.index')
                ->with('error', $exception->getMessage()));
        }
    }
}
