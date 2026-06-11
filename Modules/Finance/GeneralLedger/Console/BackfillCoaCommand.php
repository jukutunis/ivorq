<?php

namespace Modules\Finance\GeneralLedger\Console;

use Illuminate\Console\Command;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;
use Illuminate\Support\Facades\DB;

class BackfillCoaCommand extends Command
{
    protected $signature = 'finance:backfill-coa';
    protected $description = 'Backfills account_category and is_cash_equivalent for existing GL accounts';

    public function handle()
    {
        $this->info('Starting COA Backfill...');

        $accounts = Account::whereNull('account_category')->get();

        if ($accounts->isEmpty()) {
            $this->info('No accounts require backfilling. Process is idempotent.');
            return self::SUCCESS;
        }

        $count = 0;

        foreach ($accounts as $account) {
            $category = match ($account->account_type) {
                AccountTypeEnum::Asset => AccountCategoryEnum::CurrentAsset,
                AccountTypeEnum::Liability => AccountCategoryEnum::CurrentLiability,
                AccountTypeEnum::Equity => AccountCategoryEnum::Equity,
                AccountTypeEnum::Revenue => AccountCategoryEnum::Revenue,
                AccountTypeEnum::CostOfSales => AccountCategoryEnum::CostOfSales,
                AccountTypeEnum::Expense => AccountCategoryEnum::Expense,
                AccountTypeEnum::Statistical => AccountCategoryEnum::Statistical,
            };

            // Update directly to bypass model events (e.g. immutability checks that might be triggered)
            DB::table('gl_accounts')
                ->where('id', $account->id)
                ->update([
                    'account_category' => $category->value,
                    'is_cash_equivalent' => false,
                ]);

            $count++;
        }

        $this->info("Backfill complete. Updated $count accounts.");
        return self::SUCCESS;
    }
}
