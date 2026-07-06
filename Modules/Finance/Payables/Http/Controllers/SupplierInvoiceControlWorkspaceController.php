<?php

namespace Modules\Finance\Payables\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Finance\Payables\Services\SupplierInvoiceApprovalService;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Throwable;

class SupplierInvoiceControlWorkspaceController extends Controller
{
    private const WORKSPACE_ROUTE = 'finance.payables.supplier-invoices.index';

    public function __construct(
        private readonly SupplierInvoiceApprovalService $approvalService,
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $propertyId = $this->resolvePropertyId($request);

        $invoices = ApInvoice::with(['vendor', 'lines'])
            ->where('property_id', $propertyId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (ApInvoice $inv) => [
                'id' => $inv->id,
                'vendor_invoice_number' => $inv->vendor_invoice_number,
                'vendor_name' => $inv->vendor?->name,
                'invoice_date' => $inv->invoice_date?->toDateString(),
                'due_date' => $inv->due_date?->toDateString(),
                'grand_total_amount' => (string) ($inv->grand_total_amount ?? '0'),
                'subtotal_amount' => (string) ($inv->subtotal_amount ?? '0'),
                'tax_amount' => (string) ($inv->tax_amount ?? '0'),
                'amount_paid' => (string) ($inv->amount_paid ?? '0'),
                'status' => $inv->status?->value,
                'status_label' => $this->statusLabel($inv->status?->value),
                'payment_status' => $inv->payment_status?->value,
                'approved_at' => $inv->approved_at?->toIso8601String(),
                'rejected_at' => $inv->rejected_at?->toIso8601String(),
                'rejection_reason' => $inv->rejection_reason,
                'line_count' => $inv->lines?->count() ?? 0,
            ])
            ->values();

        return Inertia::render('Ivorq/Finance/SupplierInvoiceControlWorkspace', [
            'invoices' => $invoices,
            'permissions' => [
                'can_approve' => $user?->can(SupplierInvoiceApprovalService::PERMISSION) ?? false,
                'can_reject' => $user?->can(SupplierInvoiceApprovalService::PERMISSION) ?? false,
            ],
        ]);
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

    private function findScopedInvoice(string $invoiceId, string $propertyId): SupplierInvoice
    {
        return SupplierInvoice::where('property_id', $propertyId)->findOrFail($invoiceId);
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
        return match($status) {
            'draft' => 'Draft',
            'pending_review' => 'Pending Review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'posted' => 'Posted',
            'voided' => 'Voided',
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
