<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class FinanceController extends Controller
{
    public function revenueCash()
    {
        return Inertia::render('Ivorq/Finance/FinanceWorkspace', ['activeTab' => 'revenue_cash']);
    }

    public function accountsPayable()
    {
        return Inertia::render('Ivorq/Finance/FinanceWorkspace', ['activeTab' => 'accounts_payable']);
    }

    public function accountsReceivable()
    {
        return Inertia::render('Ivorq/Finance/FinanceWorkspace', ['activeTab' => 'accounts_receivable']);
    }

    public function budgetWatch()
    {
        return Inertia::render('Ivorq/Finance/FinanceWorkspace', ['activeTab' => 'budget_watch']);
    }
}
