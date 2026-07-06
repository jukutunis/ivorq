<?php

namespace Modules\Finance\GeneralCashier\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Operations\GeneralCashier\Models\CashbookTransaction;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\GeneralCashier\Models\CashierPaymentInstrument;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Enums\CashierPaymentInstrumentTypeEnum;
use Modules\Finance\Payables\Models\PaymentProposal;
use Modules\Finance\Payables\Models\PaymentProposalItem;
use Shared\Services\CurrentPropertyService;

class CashbookEvidenceWorkspaceController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $propertyId = $this->resolvePropertyId($request);
        $actor = $request->user();

        $transactions = CashbookTransaction::with(['journalEntry', 'paymentExecution'])
            ->where('property_id', $propertyId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (CashbookTransaction $tx) => [
                'id' => $tx->id,
                'direction' => $tx->direction?->value,
                'amount' => (string) ($tx->amount ?? '0'),
                'currency_code' => $tx->currency_code,
                'posted_business_date' => $tx->posted_business_date,
                'journal_reference' => $tx->journalEntry?->reference,
                'created_at' => $tx->created_at?->toIso8601String(),
            ])
            ->values();

        $proposals = PaymentProposal::with(['vendor'])
            ->where('property_id', $propertyId)
            ->whereIn('status', ['APPROVED'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (PaymentProposal $p) => [
                'id' => $p->id,
                'proposal_number' => $p->proposal_number,
                'vendor_name' => $p->vendor?->name,
                'total_amount' => (string) ($p->total_amount ?? '0'),
                'currency_code' => $p->currency_code,
                'approved_at' => $p->approved_at?->toIso8601String(),
            ])
            ->values();

        $cashExecutionContext = $this->projectCashExecutionContext($propertyId, $actor);

        return Inertia::render('Ivorq/Finance/CashbookEvidenceWorkspace', [
            'transactions' => $transactions,
            'approved_proposals' => $proposals,
            'cash_execution_context' => $cashExecutionContext,
        ]);
    }

    private function projectCashExecutionContext(string $propertyId, $actor): array
    {
        $executedItemIds = PaymentExecution::where('property_id', $propertyId)
            ->pluck('payment_proposal_item_id')
            ->unique()
            ->values()
            ->all();

        $eligibleItems = PaymentProposalItem::with(['proposal', 'supplierInvoice'])
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->whereNotIn('id', $executedItemIds)
            ->whereHas('proposal', function ($query) {
                $query->where('status', 'APPROVED');
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (PaymentProposalItem $item) => [
                'id' => $item->id,
                'proposal_number' => $item->proposal->proposal_number ?? null,
                'invoice_number' => $item->supplierInvoice->invoice_number ?? null,
                'amount' => (string) ($item->requested_payment_amount ?? $item->source_amount ?? '0'),
                'currency_code' => $item->currency_code,
                'vendor_id' => $item->vendor_id,
            ])
            ->values()
            ->all();

        $sessions = [];
        if ($actor) {
            $sessions = CashierSession::where('property_id', $propertyId)
                ->where('cashier_user_id', $actor->id)
                ->where('status', CashierSessionStatusEnum::OPEN->value)
                ->orderByDesc('opened_at')
                ->limit(5)
                ->get()
                ->map(fn (CashierSession $session) => [
                    'id' => $session->id,
                    'status' => $session->status->value,
                    'opened_at' => $session->opened_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        $instruments = CashierPaymentInstrument::where('property_id', $propertyId)
            ->where('type', CashierPaymentInstrumentTypeEnum::CASH->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (CashierPaymentInstrument $instrument) => [
                'id' => $instrument->id,
                'name' => $instrument->name,
                'type' => $instrument->type->value,
            ])
            ->values()
            ->all();

        return [
            'eligible_items' => array_values($eligibleItems),
            'cash_sessions' => array_values($sessions),
            'cash_instruments' => array_values($instruments),
        ];
    }

    private function resolvePropertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();

        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }
}
