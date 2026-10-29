<?php

namespace Modules\Finance\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Services\BalanceSheetService;
use Modules\Finance\GeneralLedger\Services\CashFlowService;
use Modules\Finance\GeneralLedger\Services\ProfitLossService;
use Modules\Finance\GeneralLedger\Services\TrialBalanceService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class FinancialReportingWorkspaceController extends Controller
{
    private const REPORTS = [
        'trial_balance' => ['permission' => 'generalledger.trialbalance.view', 'service' => TrialBalanceService::class],
        'profit_loss' => ['permission' => 'generalledger.profitloss.view', 'service' => ProfitLossService::class],
        'balance_sheet' => ['permission' => 'generalledger.balancesheet.view', 'service' => BalanceSheetService::class],
        'cash_flow' => ['permission' => 'generalledger.cashflow.view', 'service' => CashFlowService::class],
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->authorizeWorkspace($user);
        $propertyId = $this->propertyId($request);
        $latestPeriod = FinancialPeriod::query()
            ->where('property_id', $propertyId)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->first();

        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2200'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);
        $year = (int) ($validated['year'] ?? $latestPeriod?->period_year ?? now()->year);
        $month = (int) ($validated['month'] ?? $latestPeriod?->period_month ?? now()->month);

        $reports = [];
        $permissions = [];
        foreach (self::REPORTS as $name => $definition) {
            $canView = $user->can($definition['permission']);
            $permissions[$name] = $canView;
            $reports[$name] = $canView
                ? json_decode(json_encode(app($definition['service'])->generate($propertyId, $year, $month), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)
                : null;
        }

        return Inertia::render('Ivorq/Finance/FinancialReportingWorkspace', [
            'period' => [
                'year' => $year,
                'month' => $month,
                'status' => $latestPeriod && $latestPeriod->period_year === $year && $latestPeriod->period_month === $month
                    ? self::value($latestPeriod->status)
                    : FinancialPeriod::where('property_id', $propertyId)->where('period_year', $year)->where('period_month', $month)->value('status'),
            ],
            'reports' => $reports,
            'permissions' => $permissions,
            'authority' => 'SERVER_GENERATED_FROM_PROPERTY_SCOPED_LEDGER',
        ]);
    }

    private function propertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();
        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }

    private function authorizeWorkspace(?User $user): void
    {
        if ($user && collect(self::REPORTS)->contains(fn ($definition) => $user->can($definition['permission']))) {
            return;
        }

        abort(403, 'Unauthorized.');
    }

    private static function value(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : ($value === null ? null : (string) $value);
    }
}
