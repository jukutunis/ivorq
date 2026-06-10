<?php

namespace Modules\Finance\GeneralLedger\Repositories;

use Modules\Finance\GeneralLedger\Models\LedgerBalance;

class LedgerBalanceRepository
{
    public function getBalanceRecord(string $propertyId, string $accountId, int $year, int $month): LedgerBalance
    {
        return LedgerBalance::firstOrCreate(
            [
                'property_id' => $propertyId,
                'account_id' => $accountId,
                'period_year' => $year,
                'period_month' => $month,
            ],
            [
                'debit_total' => 0,
                'credit_total' => 0,
                'ending_balance' => 0,
            ]
        );
    }

    public function save(LedgerBalance $balance): bool
    {
        return $balance->save();
    }
}
