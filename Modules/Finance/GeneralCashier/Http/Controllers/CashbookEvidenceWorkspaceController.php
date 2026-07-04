<?php

namespace Modules\Finance\GeneralCashier\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Operations\GeneralCashier\Models\CashbookTransaction;
use Modules\Finance\Payables\Models\PaymentProposal;
use Shared\Services\CurrentPropertyService;

class CashbookEvidenceWorkspaceController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $propertyId = $this->resolvePropertyId($request);

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

        return Inertia::render('Ivorq/Finance/CashbookEvidenceWorkspace', [
            'transactions' => $transactions,
            'approved_proposals' => $proposals,
        ]);
    }

    private function resolvePropertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();

        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }
}
