<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Finance\Budgeting\Models\Budget;
use Modules\Finance\Budgeting\Services\BudgetVarianceService;
use Modules\Finance\Forecasting\Models\Forecast;
use Modules\Finance\Forecasting\Services\ForecastVarianceService;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Finance\Treasury\Models\BankBalanceSnapshot;
use Modules\Finance\Treasury\Models\TreasuryAlertLog;
use Modules\Finance\Treasury\Services\TreasuryService;
use Modules\Operations\PMS\Enums\GuestArTransferStatusEnum;
use Modules\Operations\PMS\Models\GuestArTransferRequest;

class FinanceOperationsProjectionService
{
    public function __construct(
        private readonly TreasuryService $treasury,
        private readonly BudgetVarianceService $budgetVariance,
        private readonly ForecastVarianceService $forecastVariance,
    ) {}

    public function project(string $propertyId, int $year, int $month, array $permissions): array
    {
        $ar = $permissions['can_view_ar'] ? $this->accountsReceivable($propertyId) : null;
        $treasury = $permissions['can_view_treasury'] ? $this->treasury($propertyId, $year, $ar) : null;
        $budget = $permissions['can_view_budget'] ? $this->budget($propertyId, $year, $month) : null;
        $forecast = $permissions['can_view_forecast'] ? $this->forecast($propertyId, $year) : null;
        $period = $this->period($propertyId, $year, $month);

        return [
            'scope' => ['property_id' => $propertyId, 'year' => $year, 'month' => $month],
            'summary' => $this->summary($ar, $treasury, $budget, $forecast, $period),
            'accounts_receivable' => $ar,
            'treasury' => $treasury,
            'budget' => $budget,
            'forecast' => $forecast,
            'financial_period' => $period,
            'planning_accounts' => ($permissions['can_manage_budget'] || $permissions['can_manage_forecast'])
                ? $this->planningAccounts($propertyId)
                : [],
            'authority' => [
                'property' => 'SERVER_RESOLVED_ACTIVE_PROPERTY',
                'ar' => 'ACCEPTED_UNREVERSED_GUEST_AR_TRANSFER_EVIDENCE',
                'cash' => 'PROPERTY_SCOPED_GENERAL_LEDGER_CASH_EQUIVALENTS',
                'bank' => 'IMMUTABLE_TREASURY_BANK_BALANCE_SNAPSHOTS',
                'planning_actuals' => 'PROPERTY_SCOPED_GENERAL_LEDGER_BALANCES',
            ],
            'deferred' => [
                'AR company-account identity, payment terms, receipt allocation, unapplied receipts, credit limits, chargebacks, and write-offs require accepted City Ledger accounting contracts and schema.',
                'External bank APIs, money movement, payment initiation, and live balance synchronization are out of scope.',
            ],
        ];
    }

    private function accountsReceivable(string $propertyId): array
    {
        $items = GuestArTransferRequest::query()
            ->where('property_id', $propertyId)
            ->where('lifecycle_status', GuestArTransferStatusEnum::Accepted->value)
            ->with(['decisions' => fn ($query) => $query->orderBy('decided_at')])
            ->orderByDesc('requested_at')
            ->get()
            ->map(function (GuestArTransferRequest $request): array {
                $accepted = $request->decisions->first(
                    fn ($decision) => self::value($decision->decision_type) === 'ACCEPTED'
                );
                $acceptedAt = $accepted?->decided_at ?? $request->requested_at;
                $ageDays = max(0, (int) $acceptedAt->diffInDays(now()));

                return [
                    'id' => $request->id,
                    'transfer_number' => $request->transfer_number,
                    'folio_id' => $request->folio_id,
                    'reservation_id' => $request->reservation_id,
                    'guest_id' => $request->guest_id,
                    'currency' => $request->currency,
                    'amount' => (string) $request->amount,
                    'accepted_at' => $acceptedAt?->toIso8601String(),
                    'age_days' => $ageDays,
                    'aging_bucket' => $this->agingBucket($ageDays),
                    'source_decision_id' => $accepted?->id,
                    'source_status' => 'ACCEPTED',
                ];
            });

        return [
            'items' => $items->values()->all(),
            'totals_by_currency' => $this->currencyTotals($items),
            'aging' => $items->countBy('aging_bucket')->all(),
            'active_count' => $items->count(),
            'authority_note' => 'Requested, rejected, and reversed transfers are excluded. Amount is active transfer exposure; settlement allocation is not inferred.',
        ];
    }

    private function treasury(string $propertyId, int $year, ?array $ar): array
    {
        $snapshots = BankBalanceSnapshot::query()
            ->where('property_id', $propertyId)
            ->orderByDesc('snapshot_date')
            ->orderByDesc('created_at')
            ->get()
            ->unique('bank_account_id')
            ->values()
            ->map(fn (BankBalanceSnapshot $snapshot): array => [
                'bank_account_id' => $snapshot->bank_account_id,
                'snapshot_date' => Carbon::parse($snapshot->snapshot_date)->toDateString(),
                'balance' => (string) $snapshot->balance,
            ]);

        $payables = SupplierInvoice::query()
            ->where('property_id', $propertyId)
            ->where('status', SupplierInvoice::STATUS_APPROVED)
            ->get(['currency_code', 'grand_total', 'due_date']);

        $alerts = TreasuryAlertLog::query()
            ->where('property_id', $propertyId)
            ->orderByDesc('logged_at')
            ->limit(20)
            ->get()
            ->map(fn (TreasuryAlertLog $alert): array => [
                'type' => $alert->alert_type,
                'severity' => self::value($alert->severity),
                'message' => $alert->message,
                'logged_at' => $alert->logged_at?->toIso8601String(),
            ]);

        return [
            'cash_position' => $this->treasury->getCurrentCashPosition($propertyId, $year),
            'bank_snapshots' => $snapshots->all(),
            'approved_payables_by_currency' => $payables->groupBy('currency_code')->map(fn (Collection $rows) => [
                'count' => $rows->count(),
                'amount' => (string) $rows->sum(fn ($row) => (float) $row->grand_total),
                'overdue_count' => $rows->filter(fn ($row) => $row->due_date?->isPast())->count(),
            ])->all(),
            'accepted_receivables_by_currency' => $ar['totals_by_currency'] ?? [],
            'alerts' => $alerts->all(),
            'read_side_effects' => 0,
        ];
    }

    private function budget(string $propertyId, int $year, int $month): array
    {
        $budgets = Budget::query()
            ->where('property_id', $propertyId)
            ->where('fiscal_year', $year)
            ->with(['versions' => fn ($query) => $query->with('lines')->orderByDesc('version_number')])
            ->orderByDesc('created_at')
            ->get();

        return [
            'records' => $budgets->map(fn (Budget $budget): array => [
                'id' => $budget->id,
                'name' => $budget->name,
                'fiscal_year' => $budget->fiscal_year,
                'versions' => $budget->versions->map(fn ($version): array => [
                    'id' => $version->id,
                    'number' => $version->version_number,
                    'status' => self::value($version->status),
                    'line_count' => $version->lines->count(),
                    'total' => (string) $version->lines->sum(fn ($line) => (float) $line->amount),
                    'created_by' => $version->created_by,
                ])->all(),
            ])->all(),
            'variance' => $this->budgetVariance->getVariance($propertyId, $year, $month),
        ];
    }

    private function forecast(string $propertyId, int $year): array
    {
        $forecasts = Forecast::query()
            ->where('property_id', $propertyId)
            ->where('fiscal_year', $year)
            ->with(['versions' => fn ($query) => $query->with('lines')->orderByDesc('version_number')])
            ->orderByDesc('created_at')
            ->get();

        return [
            'records' => $forecasts->map(fn (Forecast $forecast): array => [
                'id' => $forecast->id,
                'name' => $forecast->name,
                'fiscal_year' => $forecast->fiscal_year,
                'versions' => $forecast->versions->map(fn ($version): array => [
                    'id' => $version->id,
                    'number' => $version->version_number,
                    'status' => self::value($version->status),
                    'line_count' => $version->lines->count(),
                    'total' => (string) $version->lines->sum(fn ($line) => (float) $line->amount),
                    'created_by' => $version->created_by,
                ])->all(),
            ])->all(),
            'variance' => $this->forecastVariance->getVariance($propertyId, $year),
        ];
    }

    private function period(string $propertyId, int $year, int $month): ?array
    {
        $period = FinancialPeriod::query()
            ->where('property_id', $propertyId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();

        return $period ? [
            'year' => $period->period_year,
            'month' => $period->period_month,
            'status' => self::value($period->status),
        ] : null;
    }

    private function planningAccounts(string $propertyId): array
    {
        return Account::query()
            ->where('property_id', $propertyId)
            ->whereIn('account_type', [
                AccountTypeEnum::Revenue->value,
                AccountTypeEnum::CostOfSales->value,
                AccountTypeEnum::Expense->value,
            ])
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->toArray();
    }

    private function summary(?array $ar, ?array $treasury, ?array $budget, ?array $forecast, ?array $period): array
    {
        $cards = [];
        if ($treasury !== null) {
            $cards[] = ['key' => 'cash_position', 'label' => 'GL cash-equivalent position', 'value' => $treasury['cash_position']];
            $cards[] = ['key' => 'bank_sources', 'label' => 'Current bank snapshots', 'value' => count($treasury['bank_snapshots'])];
        }
        if ($ar !== null) {
            $cards[] = ['key' => 'ar_exposure', 'label' => 'Accepted AR transfers', 'value' => $ar['active_count']];
        }
        if ($budget !== null) {
            $cards[] = ['key' => 'budget_versions', 'label' => 'Budget records', 'value' => count($budget['records'])];
        }
        if ($forecast !== null) {
            $cards[] = ['key' => 'forecast_versions', 'label' => 'Forecast records', 'value' => count($forecast['records'])];
        }
        $cards[] = ['key' => 'period_readiness', 'label' => 'Financial Period', 'value' => $period['status'] ?? 'NOT_INITIALIZED'];

        return $cards;
    }

    private function currencyTotals(Collection $items): array
    {
        return $items->groupBy('currency')->map(fn (Collection $rows) => [
            'count' => $rows->count(),
            'amount' => (string) $rows->sum(fn (array $row) => (float) $row['amount']),
        ])->all();
    }

    private function agingBucket(int $days): string
    {
        return match (true) {
            $days <= 30 => '0_30',
            $days <= 60 => '31_60',
            $days <= 90 => '61_90',
            default => '91_plus',
        };
    }

    private static function value(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : ($value === null ? null : (string) $value);
    }
}
