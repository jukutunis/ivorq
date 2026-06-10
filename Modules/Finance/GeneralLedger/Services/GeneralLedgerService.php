<?php

namespace Modules\Finance\GeneralLedger\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Exception;

class GeneralLedgerService
{
    public function postJournalEntry(string $journalEntryId): JournalEntry
    {
        return DB::transaction(function () use ($journalEntryId) {
            $entry = JournalEntry::with('lines')->lockForUpdate()->findOrFail($journalEntryId);

            if ($entry->status === JournalStatusEnum::Posted) {
                throw new Exception('Journal entry is already posted and immutable.');
            }

            $propertyId = $entry->property_id;
            $debitTotal = 0.0;
            $creditTotal = 0.0;

            $accountIds = $entry->lines->pluck('account_id')->unique();
            $accounts = Account::whereIn('id', $accountIds)->lockForUpdate()->get()->keyBy('id');

            foreach ($entry->lines as $line) {
                if ($line->property_id !== $propertyId) {
                    throw new Exception('Cross-property journals are blocked.');
                }

                $account = $accounts->get($line->account_id);
                if (!$account) {
                    throw new Exception('Account not found.');
                }
                if (!$account->is_active) {
                    throw new Exception('Cannot post to inactive account.');
                }

                if ($account->account_type === AccountTypeEnum::Statistical) {
                    if ($line->debit_amount > 0 || $line->credit_amount > 0) {
                        throw new Exception('Statistical accounts cannot be used with money debit/credit amounts.');
                    }
                }

                $debitTotal += (float) $line->debit_amount;
                $creditTotal += (float) $line->credit_amount;
            }

            if (round($debitTotal, 2) !== round($creditTotal, 2)) {
                throw new Exception('Out-of-balance journal cannot be posted.');
            }

            $year = (int) date('Y', strtotime($entry->transaction_date));
            $month = (int) date('m', strtotime($entry->transaction_date));

            // Lock Ledger Balances
            $balances = LedgerBalance::where('property_id', $propertyId)
                ->whereIn('account_id', $accountIds)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->lockForUpdate()
                ->get()
                ->keyBy('account_id');

            foreach ($entry->lines as $line) {
                $balance = $balances->get($line->account_id);
                if (!$balance) {
                    $balance = LedgerBalance::create([
                        'property_id' => $propertyId,
                        'account_id' => $line->account_id,
                        'period_year' => $year,
                        'period_month' => $month,
                        'debit_total' => 0,
                        'credit_total' => 0,
                        'ending_balance' => 0,
                    ]);
                    $balances->put($line->account_id, $balance);
                }

                $balance->debit_total += (float) $line->debit_amount;
                $balance->credit_total += (float) $line->credit_amount;

                $account = $accounts->get($line->account_id);
                
                if ($account->normal_balance === NormalBalanceEnum::Debit) {
                    $balance->ending_balance = $balance->debit_total - $balance->credit_total;
                } elseif ($account->normal_balance === NormalBalanceEnum::Credit) {
                    $balance->ending_balance = $balance->credit_total - $balance->debit_total;
                } else {
                    $balance->ending_balance = $balance->debit_total - $balance->credit_total;
                }

                $balance->save();
            }

            $entry->status = JournalStatusEnum::Posted;
            $entry->posting_date = now()->toDateString();
            $entry->save();

            return $entry;
        });
    }
}
