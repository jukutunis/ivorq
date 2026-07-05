<?php

namespace Modules\Finance\Payables\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Shared\Services\CurrentPropertyService;

class SupplierInvoiceControlWorkspaceController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
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
        ]);
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
