<?php

namespace Modules\Finance\GeneralLedger\Services;

use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\DTOs\ProfitLossDTO;
use Modules\Finance\GeneralLedger\DTOs\ProfitLossLineDTO;

class ProfitLossService
{
    public function generate(string $propertyId, int $year, int $month): ProfitLossDTO
    {
        $accounts = Account::where('property_id', $propertyId)
            ->where('is_active', true)
            ->whereIn('account_type', [
                AccountTypeEnum::Revenue,
                AccountTypeEnum::CostOfSales,
                AccountTypeEnum::Expense,
            ])
            ->get();

        $balances = LedgerBalance::where('property_id', $propertyId)
            ->whereIn('account_id', $accounts->pluck('id'))
            ->where('period_year', $year)
            ->where('period_month', '<=', $month)
            ->get()
            ->groupBy('account_id');

        $revenueLines = [];
        $cosLines = [];
        $expenseLines = [];

        $pRev = 0.0; $yRev = 0.0;
        $pCos = 0.0; $yCos = 0.0;
        $pExp = 0.0; $yExp = 0.0;

        foreach ($accounts as $account) {
            $accountBalances = $balances->get($account->id) ?? collect();

            $pDebit = 0.0; $pCredit = 0.0;
            $yDebit = 0.0; $yCredit = 0.0;

            foreach ($accountBalances as $balance) {
                if ($balance->period_month === $month) {
                    $pDebit += (float) $balance->debit_total;
                    $pCredit += (float) $balance->credit_total;
                }
                $yDebit += (float) $balance->debit_total;
                $yCredit += (float) $balance->credit_total;
            }

            $pAmount = 0.0;
            $yAmount = 0.0;

            if ($account->account_type === AccountTypeEnum::Revenue) {
                $pAmount = $pCredit - $pDebit;
                $yAmount = $yCredit - $yDebit;
            } else {
                // CostOfSales and Expense
                $pAmount = $pDebit - $pCredit;
                $yAmount = $yDebit - $yCredit;
            }

            if ($pAmount !== 0.0 || $yAmount !== 0.0 || $yDebit !== 0.0 || $yCredit !== 0.0) {
                $line = new ProfitLossLineDTO(
                    account_code: $account->code,
                    account_name: $account->name,
                    account_type: $account->account_type->value,
                    period_amount: round($pAmount, 2),
                    ytd_amount: round($yAmount, 2)
                );

                if ($account->account_type === AccountTypeEnum::Revenue) {
                    $revenueLines[] = $line;
                    $pRev += $pAmount;
                    $yRev += $yAmount;
                } elseif ($account->account_type === AccountTypeEnum::CostOfSales) {
                    $cosLines[] = $line;
                    $pCos += $pAmount;
                    $yCos += $yAmount;
                } elseif ($account->account_type === AccountTypeEnum::Expense) {
                    $expenseLines[] = $line;
                    $pExp += $pAmount;
                    $yExp += $yAmount;
                }
            }
        }

        // Sort lines
        usort($revenueLines, fn($a, $b) => strcmp($a->account_code, $b->account_code));
        usort($cosLines, fn($a, $b) => strcmp($a->account_code, $b->account_code));
        usort($expenseLines, fn($a, $b) => strcmp($a->account_code, $b->account_code));

        $pGP = $pRev - $pCos;
        $yGP = $yRev - $yCos;

        $pNP = $pGP - $pExp;
        $yNP = $yGP - $yExp;

        return new ProfitLossDTO(
            revenue_lines: $revenueLines,
            cost_of_sales_lines: $cosLines,
            expense_lines: $expenseLines,
            
            period_total_revenue: round($pRev, 2),
            ytd_total_revenue: round($yRev, 2),
            
            period_total_cost_of_sales: round($pCos, 2),
            ytd_total_cost_of_sales: round($yCos, 2),
            
            period_gross_profit: round($pGP, 2),
            ytd_gross_profit: round($yGP, 2),
            
            period_total_expense: round($pExp, 2),
            ytd_total_expense: round($yExp, 2),
            
            period_net_profit: round($pNP, 2),
            ytd_net_profit: round($yNP, 2),
        );
    }
}
