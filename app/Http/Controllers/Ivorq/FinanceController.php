<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Finance\Services\FinanceOperationsProjectionService;
use Shared\Services\CurrentPropertyService;

class FinanceController extends Controller
{
    public function __construct(private readonly FinanceOperationsProjectionService $projection) {}

    public function revenueCash(Request $request): Response
    {
        return $this->renderWorkspace($request, 'revenue_cash');
    }

    public function accountsPayable(Request $request): Response
    {
        return $this->renderWorkspace($request, 'accounts_payable');
    }

    public function accountsReceivable(Request $request): Response
    {
        return $this->renderWorkspace($request, 'accounts_receivable');
    }

    public function treasury(Request $request): Response
    {
        return $this->renderWorkspace($request, 'treasury');
    }

    public function budgetWatch(Request $request): Response
    {
        return $this->renderWorkspace($request, 'budget_watch');
    }

    public function forecasting(Request $request): Response
    {
        return $this->renderWorkspace($request, 'forecasting');
    }

    private function renderWorkspace(Request $request, string $activeTab): Response
    {
        $user = $request->user();
        abort_unless($user, 403, 'Unauthorized.');
        $capabilities = [
            'can_view_ar' => $user->can('accounting.ar.view'),
            'can_view_treasury' => $user->can('finance.treasury.view'),
            'can_view_budget' => $user->can('budget.view'),
            'can_create_budget' => $user->can('budget.create'),
            'can_edit_budget' => $user->can('budget.edit'),
            'can_submit_budget' => $user->can('budget.submit'),
            'can_approve_budget' => $user->can('budget.approve'),
            'can_manage_budget' => $this->canAny($user, ['budget.create', 'budget.edit', 'budget.submit', 'budget.approve']),
            'can_view_forecast' => $user->can('forecast.view'),
            'can_create_forecast' => $user->can('forecast.create'),
            'can_edit_forecast' => $user->can('forecast.edit'),
            'can_submit_forecast' => $user->can('forecast.submit'),
            'can_approve_forecast' => $user->can('forecast.approve'),
            'can_manage_forecast' => $this->canAny($user, ['forecast.create', 'forecast.edit', 'forecast.submit', 'forecast.approve']),
            'can_view_payables' => $this->canAny($user, [
                'finance.payables.supplier-invoice.register',
                'finance.payables.supplier-invoice.approve',
                'finance.payables.payment-proposal.create',
                'finance.payables.payment-proposal.approve',
            ]),
            'can_view_fx_adjustments' => $user->can('finance.fx-adjustment.view'),
            'can_manage_fx_operational_roles' => $user->can('system.finance-role-assignment.manage'),
            'can_view_general_ledger' => $this->canAny($user, [
                'finance.journal-candidate.review',
                'finance.journal-candidate.materialize-draft',
                'finance.journal-entry-draft.authorize-finalization',
                'finance.journal-entry.post',
            ]),
            'can_view_financial_reports' => $this->canAny($user, [
                'generalledger.trialbalance.view',
                'generalledger.profitloss.view',
                'generalledger.balancesheet.view',
                'generalledger.cashflow.view',
            ]),
            'can_manage_financial_period' => $user->can('generalledger.period.manage'),
            'can_view_fx_rates' => $this->canAny($user, ['finance.fx-rate.record', 'finance.fx-rate.approve']),
        ];
        abort_unless(collect($capabilities)->contains(true), 403, 'Unauthorized.');

        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2200'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);
        $year = (int) ($validated['year'] ?? now()->year);
        $month = (int) ($validated['month'] ?? now()->month);
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();
        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return Inertia::render('Ivorq/Finance/FinanceWorkspace', [
            'activeTab' => $activeTab,
            'capabilities' => $capabilities,
            'workspace' => $this->projection->project($propertyId, $year, $month, $capabilities),
        ]);
    }

    private function canAny($user, array $permissions): bool
    {
        return collect($permissions)->contains(fn (string $permission) => $user->can($permission));
    }
}
