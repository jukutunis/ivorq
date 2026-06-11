<?php

namespace Modules\Finance\GeneralLedger\Services;

use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Modules\Finance\GeneralLedger\DTOs\TrialBalanceDTO;
use Modules\Finance\GeneralLedger\DTOs\TrialBalanceLineDTO;

class TrialBalanceService
{
    public function generate(string $propertyId, int $year, int $month): TrialBalanceDTO
    {
        $accounts = Account::where('property_id', $propertyId)
            ->where('is_active', true)
            ->where('account_type', '!=', AccountTypeEnum::Statistical)
            ->get();

        $lines = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        // Eager load all balances for these accounts up to the requested year/month
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

        foreach ($accounts as $account) {
            $accountBalances = $balances->get($account->id) ?? collect();

            $openingDebit = 0.0;
            $openingCredit = 0.0;
            $periodDebit = 0.0;
            $periodCredit = 0.0;

            foreach ($accountBalances as $balance) {
                if ($balance->period_year === $year && $balance->period_month === $month) {
                    $periodDebit += (float) $balance->debit_total;
                    $periodCredit += (float) $balance->credit_total;
                } else {
                    // It's a prior period
                    $isPnL = in_array($account->account_type, [
                        AccountTypeEnum::Revenue,
                        AccountTypeEnum::CostOfSales,
                        AccountTypeEnum::Expense,
                    ]);

                    if ($isPnL) {
                        // Reset at fiscal year start (only include current year's prior months)
                        if ($balance->period_year === $year) {
                            $openingDebit += (float) $balance->debit_total;
                            $openingCredit += (float) $balance->credit_total;
                        }
                    } else {
                        // Balance Sheet accounts carry forward from beginning of time
                        $openingDebit += (float) $balance->debit_total;
                        $openingCredit += (float) $balance->credit_total;
                    }
                }
            }

            // Calculate Opening Balance based on normal balance
            $openingBalance = 0.0;
            if ($account->normal_balance === NormalBalanceEnum::Debit) {
                $openingBalance = $openingDebit - $openingCredit;
            } elseif ($account->normal_balance === NormalBalanceEnum::Credit) {
                $openingBalance = $openingCredit - $openingDebit;
            }

            // Calculate Ending Balance
            $endingBalance = 0.0;
            if ($account->normal_balance === NormalBalanceEnum::Debit) {
                $endingBalance = $openingBalance + $periodDebit - $periodCredit;
            } elseif ($account->normal_balance === NormalBalanceEnum::Credit) {
                $endingBalance = $openingBalance - $periodDebit + $periodCredit;
            }

            // Only include accounts with non-zero balances or activity
            if ($openingBalance !== 0.0 || $periodDebit !== 0.0 || $periodCredit !== 0.0 || $endingBalance !== 0.0) {
                $lines[] = new TrialBalanceLineDTO(
                    account_code: $account->code,
                    account_name: $account->name,
                    account_type: $account->account_type->value,
                    opening_balance: round($openingBalance, 2),
                    debit_activity: round($periodDebit, 2),
                    credit_activity: round($periodCredit, 2),
                    ending_balance: round($endingBalance, 2)
                );

                $totalDebit += round($periodDebit, 2);
                $totalCredit += round($periodCredit, 2);
            }
        }

        // Sort by account code
        usort($lines, fn($a, $b) => strcmp($a->account_code, $b->account_code));

        $totalDebit = round($totalDebit, 2);
        $totalCredit = round($totalCredit, 2);
        $balanced = ($totalDebit === $totalCredit);

        return new TrialBalanceDTO(
            lines: $lines,
            total_debit: $totalDebit,
            total_credit: $totalCredit,
            balanced: $balanced
        );
    }
}
