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
        return Inertia::render('Ivorq/Finance/FinanceWorkspace', [
            'activeTab' => $activeTab,
            'capabilities' => [
                'can_view_fx_adjustments' => (bool) request()->user()?->can('finance.fx-adjustment.view'),
            ],
        ]);
    }
}
