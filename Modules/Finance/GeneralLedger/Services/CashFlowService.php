<?php

namespace Modules\Finance\GeneralLedger\Services;

use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;
use Modules\Finance\GeneralLedger\DTOs\CashFlowDTO;
use Modules\Finance\GeneralLedger\DTOs\CashFlowLineDTO;

class CashFlowService
{
    public function __construct(protected ProfitLossService $profitLossService) {}

    public function generate(string $propertyId, int $year, int $month): CashFlowDTO
    {
        // 1. Get Net Profit from P&L
        $plDto = $this->profitLossService->generate($propertyId, $year, $month);
        $netProfit = $plDto->ytd_net_profit;

        // 2. Compute Opening and Closing Cash
        $cashAccounts = Account::where('property_id', $propertyId)
            ->where('is_cash_equivalent', true)
            ->get();

        $openingCash = 0.0;
        $closingCash = 0.0;

        if ($cashAccounts->isNotEmpty()) {
            $cashBalances = LedgerBalance::where('property_id', $propertyId)
                ->whereIn('account_id', $cashAccounts->pluck('id'))
                ->where(function ($q) use ($year, $month) {
                    $q->where('period_year', '<', $year)
                      ->orWhere(function ($q2) use ($year, $month) {
                          $q2->where('period_year', $year)->where('period_month', '<=', $month);
                      });
                })->get();

            foreach ($cashBalances as $b) {
                // Cash accounts are assets, so normal balance is Debit. Value = debit - credit.
                $amount = (float) $b->debit_total - (float) $b->credit_total;
                
                if ($b->period_year < $year) {
                    $openingCash += $amount;
                }
                $closingCash += $amount;
            }
        }

        // 3. Compute Adjustments (Operating, Investing, Financing)
        $accounts = Account::where('property_id', $propertyId)
            ->where('is_active', true)
            ->where('is_cash_equivalent', false)
            ->whereIn('account_category', [
                AccountCategoryEnum::CurrentAsset,
                AccountCategoryEnum::CurrentLiability,
                AccountCategoryEnum::FixedAsset,
                AccountCategoryEnum::OtherAsset,
                AccountCategoryEnum::LongTermLiability,
                // Equity explicitly excluded for Sprint 11.5 to prevent double-counting Net Profit
            ])
            ->get();

        $adjBalances = LedgerBalance::where('property_id', $propertyId)
            ->whereIn('account_id', $accounts->pluck('id'))
            ->where('period_year', $year)
            ->where('period_month', '<=', $month)
            ->get()
            ->groupBy('account_id');

        $opLines = [];
        $invLines = [];
        $finLines = [];

        $netOp = $netProfit;
        $netInv = 0.0;
        $netFin = 0.0;

        foreach ($accounts as $account) {
            $bals = $adjBalances->get($account->id) ?? collect();
            
            $debit = 0.0;
            $credit = 0.0;

            foreach ($bals as $b) {
                $debit += (float) $b->debit_total;
                $credit += (float) $b->credit_total;
            }

            // Universal sign rule for indirect method cash flow impact:
            // Asset increase (debit) = negative cash flow
            // Liability increase (credit) = positive cash flow
            // Therefore, cash flow impact = credit - debit.
            $cashFlowImpact = $credit - $debit;

            if ($cashFlowImpact !== 0.0 || $debit !== 0.0 || $credit !== 0.0) {
                $line = new CashFlowLineDTO(
                    account_code: $account->code,
                    account_name: $account->name,
                    account_category: $account->account_category->value,
                    amount: round($cashFlowImpact, 2)
                );

                if (in_array($account->account_category, [AccountCategoryEnum::CurrentAsset, AccountCategoryEnum::CurrentLiability])) {
                    $opLines[] = $line;
                    $netOp += $cashFlowImpact;
                } elseif (in_array($account->account_category, [AccountCategoryEnum::FixedAsset, AccountCategoryEnum::OtherAsset])) {
                    $invLines[] = $line;
                    $netInv += $cashFlowImpact;
                } elseif ($account->account_category === AccountCategoryEnum::LongTermLiability) {
                    $finLines[] = $line;
                    $netFin += $cashFlowImpact;
                }
            }
        }

        usort($opLines, fn($a, $b) => strcmp($a->account_code, $b->account_code));
        usort($invLines, fn($a, $b) => strcmp($a->account_code, $b->account_code));
        usort($finLines, fn($a, $b) => strcmp($a->account_code, $b->account_code));

        $netCashChange = $netOp + $netInv + $netFin;
        $balanced = round($openingCash + $netCashChange, 2) === round($closingCash, 2);

        return new CashFlowDTO(
            opening_cash: round($openingCash, 2),
            net_profit: round($netProfit, 2),
            operating_lines: $opLines,
            net_cash_operating: round($netOp, 2),
            investing_lines: $invLines,
            net_cash_investing: round($netInv, 2),
            financing_lines: $finLines,
            net_cash_financing: round($netFin, 2),
            net_cash_change: round($netCashChange, 2),
            closing_cash: round($closingCash, 2),
            balanced: $balanced
        );
    }
}
