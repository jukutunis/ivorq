<?php

namespace Modules\Finance\Payables\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\Payables\Services\ApGrniSettlementAgingProjectionService;
use Modules\Finance\Payables\Services\SupplierInvoiceApprovalService;
use Modules\Finance\Payables\Services\SupplierInvoiceExceptionReviewService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class ApGrniSettlementControlWorkspaceController extends Controller
{
    private const VIEW_PERMISSIONS = [
        SupplierInvoiceApprovalService::PERMISSION,
        SupplierInvoiceExceptionReviewService::PERMISSION,
        'finance.payables.grni-clearing.candidate.create',
    ];

    public function __construct(
        private readonly ApGrniSettlementAgingProjectionService $projectionService
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $this->authorizeWorkspaceAccess($user);
        $propertyId = $this->resolvePropertyId($request);

        return Inertia::render('Ivorq/Finance/ApGrniSettlementControlWorkspace', [
            'projection' => $this->projectionService->project($propertyId),
            'permissions' => [
                'can_view' => true,
            ],
        ]);
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
}
