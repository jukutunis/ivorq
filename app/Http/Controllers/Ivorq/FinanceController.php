<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class FinanceController extends Controller
{
    public function revenueCash()
    {
        return $this->renderWorkspace('revenue_cash');
    }

    public function accountsPayable()
    {
        return $this->renderWorkspace('accounts_payable');
    }

    public function accountsReceivable()
    {
        return $this->renderWorkspace('accounts_receivable');
    }

    public function budgetWatch()
    {
        return $this->renderWorkspace('budget_watch');
    }

    private function renderWorkspace(string $activeTab)
    {
        $user = request()->user();

        return Inertia::render('Ivorq/Finance/FinanceWorkspace', [
            'activeTab' => $activeTab,
            'capabilities' => [
                'can_view_fx_adjustments' => (bool) request()->user()?->can('finance.fx-adjustment.view'),
                'can_manage_fx_operational_roles' => (bool) request()->user()?->can('system.finance-role-assignment.manage'),
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
                'can_manage_financial_period' => (bool) $user?->can('generalledger.period.manage'),
                'can_view_fx_rates' => $this->canAny($user, ['finance.fx-rate.record', 'finance.fx-rate.approve']),
            ],
        ]);
    }

    private function canAny($user, array $permissions): bool
    {
        return $user && collect($permissions)->contains(fn (string $permission) => $user->can($permission));
    }
}
