<?php

namespace Modules\Finance\Payables\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Finance\Payables\Services\SupplierInvoiceApprovalService;
use Modules\Finance\Payables\Services\SupplierInvoiceExceptionReviewService;
use Modules\Finance\Payables\Services\SupplierInvoiceIntakeSourceService;
use Modules\Finance\Payables\Services\SupplierInvoiceRegistrationService;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Throwable;

class SupplierInvoiceControlWorkspaceController extends Controller
{
    private const WORKSPACE_ROUTE = 'finance.payables.supplier-invoices.index';

    public function __construct(
        private readonly SupplierInvoiceApprovalService $approvalService,
        private readonly SupplierInvoiceExceptionReviewService $exceptionReviewService,
        private readonly SupplierInvoiceRegistrationService $registrationService,
        private readonly SupplierInvoiceIntakeSourceService $intakeSourceService,
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $propertyId = $this->resolvePropertyId($request);

        $invoices = SupplierInvoice::withoutGlobalScope('property')
            ->with(['vendor', 'lines', 'threeWayMatch'])
            ->where('property_id', $propertyId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (SupplierInvoice $inv) => [
                'id' => $inv->id,
                'vendor_invoice_number' => $inv->invoice_number,
                'vendor_name' => $inv->vendor?->name,
                'invoice_date' => $inv->invoice_date?->toDateString(),
                'due_date' => $inv->due_date?->toDateString(),
                'grand_total_amount' => (string) ($inv->grand_total ?? '0'),
                'subtotal_amount' => (string) ($inv->subtotal ?? '0'),
                'tax_amount' => (string) ($inv->tax_amount ?? '0'),
                'amount_paid' => '0.00',
                'status' => $inv->status,
                'status_label' => $this->statusLabel($inv->status),
                'payment_status' => null,
                'match_status' => $inv->threeWayMatch?->match_status,
                'approved_at' => $inv->approved_at?->toIso8601String(),
                'rejected_at' => $inv->rejected_at?->toIso8601String(),
                'rejection_reason' => $inv->rejection_reason,
                'exception_resolved_at' => $inv->exception_resolved_at,
                'exception_resolution_reason' => $inv->exception_resolution_reason,
                'line_count' => $inv->lines?->count() ?? 0,
            ])
            ->values();

        return Inertia::render('Ivorq/Finance/SupplierInvoiceControlWorkspace', [
            'invoices' => $invoices,
            'intake_sources' => $this->intakeSourceService->project($propertyId),
            'permissions' => [
                'can_register' => $user?->can(SupplierInvoiceRegistrationService::PERMISSION) ?? false,
                'can_approve' => $user?->can(SupplierInvoiceApprovalService::PERMISSION) ?? false,
                'can_reject' => $user?->can(SupplierInvoiceApprovalService::PERMISSION) ?? false,
                'can_resolve_exception' => $user?->can(SupplierInvoiceExceptionReviewService::PERMISSION) ?? false,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAction($request->user(), SupplierInvoiceRegistrationService::PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        $validated = $request->validate([
            'purchase_order_id' => ['required', 'string', 'max:26'],
            'goods_receipt_id' => ['nullable', 'string', 'max:26'],
            'invoice_number' => ['required', 'string', 'max:255'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'registration_idempotency_key' => ['required', 'string', 'max:96'],
        ]);

        return $this->redirectingAction(
            fn () => $this->registrationService->registerFromSource(
                ['property_id' => $propertyId, ...$validated],
                $request->user()
            ),
            'Supplier invoice registered and matched from source evidence.'
        );
    }

    public function approve(Request $request, string $invoice): RedirectResponse
    {
        $this->authorizeAction($request->user(), SupplierInvoiceApprovalService::PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        $this->findScopedInvoice($invoice, $propertyId);

        $companyId = $request->session()->get('active_company_id');
        if (!$this->confirmationService->hasValidConfirmation($request->user(), 'finance-approval', $companyId, $propertyId)) {
            return redirect()
                ->route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval'])
                ->with('error', 'Sensitive action confirmation is required before approving supplier invoices.');
        }

        return $this->redirectingAction(
            fn () => $this->approvalService->approve($invoice, $request->user()),
            'Supplier invoice approved.'
        );
    }

    public function reject(Request $request, string $invoice): RedirectResponse
    {
        $this->authorizeAction($request->user(), SupplierInvoiceApprovalService::PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        $this->findScopedInvoice($invoice, $propertyId);

        $companyId = $request->session()->get('active_company_id');
        if (!$this->confirmationService->hasValidConfirmation($request->user(), 'finance-approval', $companyId, $propertyId)) {
            return redirect()
                ->route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval'])
                ->with('error', 'Sensitive action confirmation is required before rejecting supplier invoices.');
        }

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->redirectingAction(
            fn () => $this->approvalService->reject($invoice, $request->user(), $validated['rejection_reason']),
            'Supplier invoice rejected.'
        );
    }

    public function resolveException(Request $request, string $invoice): RedirectResponse
    {
        $this->authorizeAction($request->user(), SupplierInvoiceExceptionReviewService::PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        $this->findScopedInvoice($invoice, $propertyId);

        $companyId = $request->session()->get('active_company_id');
        if (!$this->confirmationService->hasValidConfirmation($request->user(), 'finance-approval', $companyId, $propertyId)) {
            return redirect()
                ->route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval'])
                ->with('error', 'Sensitive action confirmation is required before resolving supplier invoice exceptions.');
        }

        $validated = $request->validate([
            'resolution_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->redirectingAction(
            fn () => $this->exceptionReviewService->resolveException($invoice, $request->user(), $validated['resolution_reason']),
            'Supplier invoice exception resolved.'
        );
    }

    private function findScopedInvoice(string $invoiceId, string $propertyId): SupplierInvoice
    {
        return SupplierInvoice::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->findOrFail($invoiceId);
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

    private function authorizeAction(?User $user, string $permission): void
    {
        if (!$user || !$user->can($permission)) {
            abort(403, 'Unauthorized.');
        }
    }

    private function statusLabel(?string $status): string
    {
        return match(strtoupper((string) $status)) {
            'REGISTERED' => 'Registered',
            'APPROVED' => 'Approved',
            'REJECTED' => 'Rejected',
            default => $status ?? 'Unknown',
        };
    }

    private function resolvePropertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();

        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }
}
