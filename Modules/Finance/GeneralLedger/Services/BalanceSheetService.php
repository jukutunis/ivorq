<?php

namespace Modules\Finance\GeneralLedger\Services;

use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\DTOs\BalanceSheetDTO;
use Modules\Finance\GeneralLedger\DTOs\BalanceSheetLineDTO;

class BalanceSheetService
{
    public function __construct(protected ProfitLossService $profitLossService) {}

    public function generate(string $propertyId, int $year, int $month): BalanceSheetDTO
    {
        // 1. Calculate Prior Year Retained Earnings Dynamically
        $priorYearRetainedEarnings = $this->calculatePriorYearRetainedEarnings($propertyId, $year);

        // 2. Fetch Current Year Earnings from ProfitLossService
        $plDto = $this->profitLossService->generate($propertyId, $year, $month);
        $currentYearEarnings = $plDto->ytd_net_profit;

        // 3. Process Asset, Liability, Equity accounts
        $accounts = Account::where('property_id', $propertyId)
            ->where('is_active', true)
            ->whereIn('account_type', [
                AccountTypeEnum::Asset,
                AccountTypeEnum::Liability,
                AccountTypeEnum::Equity,
            ])
            ->get();

        $balances = LedgerBalance::where('property_id', $propertyId)
            ->whereIn('account_id', $accounts->pluck('id'))
            ->where(function ($query) use ($year, $month) {
                $query->where('period_year', '<', $year)
                      ->orWhere(function ($q) use ($year, $month) {
                          $q->where('period_year', $year)
                            ->where('period_month', '<=', $month);
                      });
            })
            ->get()
            ->groupBy('account_id');

        $assetLines = [];
        $liabilityLines = [];
        $equityLines = [];

        $totalAssets = 0.0;
        $totalLiabilities = 0.0;
        $totalEquityBase = 0.0; // base equity without earnings

        foreach ($accounts as $account) {
            $accountBalances = $balances->get($account->id) ?? collect();

            $debit = 0.0;
            $credit = 0.0;

            foreach ($accountBalances as $balance) {
                $debit += (float) $balance->debit_total;
                $credit += (float) $balance->credit_total;
            }

            $amount = 0.0;

            if ($account->account_type === AccountTypeEnum::Asset) {
                $amount = $debit - $credit;
            } elseif ($account->account_type === AccountTypeEnum::Liability) {
                $amount = $credit - $debit;
            } elseif ($account->account_type === AccountTypeEnum::Equity) {
                $amount = $credit - $debit;
            }

            if ($amount !== 0.0 || $debit !== 0.0 || $credit !== 0.0) {
                $line = new BalanceSheetLineDTO(
                    account_code: $account->code,
                    account_name: $account->name,
                    account_type: $account->account_type->value,
                    balance: round($amount, 2)
                );

                if ($account->account_type === AccountTypeEnum::Asset) {
                    $assetLines[] = $line;
                    $totalAssets += $amount;
                } elseif ($account->account_type === AccountTypeEnum::Liability) {
                    $liabilityLines[] = $line;
                    $totalLiabilities += $amount;
                } elseif ($account->account_type === AccountTypeEnum::Equity) {
                    $equityLines[] = $line;
                    $totalEquityBase += $amount;
                }
            }
        }

        usort($assetLines, fn($a, $b) => strcmp($a->account_code, $b->account_code));
        usort($liabilityLines, fn($a, $b) => strcmp($a->account_code, $b->account_code));
        usort($equityLines, fn($a, $b) => strcmp($a->account_code, $b->account_code));

        $totalEquity = $totalEquityBase + $priorYearRetainedEarnings + $currentYearEarnings;

        $totalAssets = round($totalAssets, 2);
        $totalLiabilities = round($totalLiabilities, 2);
        $totalEquity = round($totalEquity, 2);

        $balanced = round($totalAssets, 2) === round($totalLiabilities + $totalEquity, 2);

        return new BalanceSheetDTO(
            asset_lines: $assetLines,
            liability_lines: $liabilityLines,
            equity_lines: $equityLines,
            
            prior_year_retained_earnings: round($priorYearRetainedEarnings, 2),
            current_year_earnings: round($currentYearEarnings, 2),
            
            total_assets: $totalAssets,
            total_liabilities: $totalLiabilities,
            total_equity: $totalEquity,
            
            balanced: $balanced
        );
    }

    protected function calculatePriorYearRetainedEarnings(string $propertyId, int $year): float
    {
        $pnlAccounts = Account::where('property_id', $propertyId)
            ->whereIn('account_type', [
                AccountTypeEnum::Revenue,
                AccountTypeEnum::CostOfSales,
                AccountTypeEnum::Expense,
            ])
            ->get();

        if ($pnlAccounts->isEmpty()) {
            return 0.0;
        }

        $balances = LedgerBalance::where('property_id', $propertyId)
            ->whereIn('account_id', $pnlAccounts->pluck('id'))
            ->where('period_year', '<', $year)
            ->get()
            ->groupBy('account_id');

        $rev = 0.0;
        $cos = 0.0;
        $exp = 0.0;

        foreach ($pnlAccounts as $account) {
            $accountBalances = $balances->get($account->id) ?? collect();
            
            $debit = 0.0;
            $credit = 0.0;

            foreach ($accountBalances as $balance) {
                $debit += (float) $balance->debit_total;
                $credit += (float) $balance->credit_total;
            }

            if ($account->account_type === AccountTypeEnum::Revenue) {
                $rev += ($credit - $debit);
            } elseif ($account->account_type === AccountTypeEnum::CostOfSales) {
                $cos += ($debit - $credit);
            } elseif ($account->account_type === AccountTypeEnum::Expense) {
                $exp += ($debit - $credit);
            }
        }

        return $rev - $cos - $exp;
    }
}
