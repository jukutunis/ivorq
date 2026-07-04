<?php

namespace Modules\Finance\Payables\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\Payables\Enums\PaymentProposalStatusEnum;
use Modules\Finance\Payables\Models\PaymentProposal;
use Modules\Finance\Payables\Services\PaymentProposalService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class PaymentProposalControlWorkspaceController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $propertyId = $this->resolvePropertyId($request);

        $proposals = PaymentProposal::with([
            'vendor',
            'items' => function ($query) {
                $query->where('is_active', true);
            },
            'items.supplierInvoice',
            'items.sourceJournalEntry',
            'submitter:id,name',
        ])
            ->where('property_id', $propertyId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (PaymentProposal $proposal) => $this->proposalPayload($proposal))
            ->values();

        return Inertia::render('Ivorq/Finance/PaymentProposalControlWorkspace', [
            'proposals' => $proposals,
            'permissions' => [
                'can_create' => $user?->can(PaymentProposalService::CREATE_PERMISSION) ?? false,
                'can_cancel' => $user?->can(PaymentProposalService::CANCEL_PERMISSION) ?? false,
            ],
        ]);
    }

    private function proposalPayload(PaymentProposal $proposal): array
    {
        $items = $proposal->items->map(function ($item) {
            return [
                'id' => $item->id,
                'source_amount' => (string) ($item->source_amount ?? '0'),
                'currency_code' => $item->currency_code,
                'invoice_number' => $item->supplierInvoice?->vendor_invoice_number,
                'invoice_id' => $item->supplierInvoice?->id,
                'journal_reference' => $item->sourceJournalEntry?->reference,
            ];
        })->values()->all();

        return [
            'id' => $proposal->id,
            'proposal_number' => $proposal->proposal_number,
            'vendor_name' => $proposal->vendor?->name,
            'currency_code' => $proposal->currency_code,
            'total_amount' => (string) ($proposal->total_amount ?? '0'),
            'status' => $proposal->status?->value,
            'status_label' => $this->statusLabel($proposal->status),
            'submitted_by' => $proposal->submitter?->name,
            'submitted_at' => $proposal->submitted_at?->toIso8601String(),
            'cancelled_at' => $proposal->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $proposal->cancellation_reason,
            'approved_at' => $proposal->approved_at?->toIso8601String(),
            'rejected_at' => $proposal->rejected_at?->toIso8601String(),
            'items' => $items,
        ];
    }

    private function statusLabel(?PaymentProposalStatusEnum $status): string
    {
        return match($status) {
            PaymentProposalStatusEnum::DRAFT => 'Draft',
            PaymentProposalStatusEnum::PENDING_APPROVAL => 'Pending Approval',
            PaymentProposalStatusEnum::APPROVED => 'Approved',
            PaymentProposalStatusEnum::REJECTED => 'Rejected',
            PaymentProposalStatusEnum::CANCELLED => 'Cancelled',
            default => 'Unknown',
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
