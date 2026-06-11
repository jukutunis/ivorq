<?php

namespace Modules\Finance\GeneralLedger\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;

class Account extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory;

    protected $table = 'gl_accounts';

    protected $fillable = [
        'property_id',
        'master_account_id',
        'code',
        'name',
        'normal_balance',
        'account_type',
        'account_category',
        'is_active',
        'is_cash_equivalent',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_cash_equivalent' => 'boolean',
        'normal_balance' => NormalBalanceEnum::class,
        'account_type' => AccountTypeEnum::class,
        'account_category' => AccountCategoryEnum::class,
    ];

    public static function boot()
    {
        parent::boot();

        static::saving(function (self $account) {
            // Ensure category is set
            if (!$account->account_category) {
                return; // Let database/validation handle nullability constraints if necessary
            }

            // BR-003: Compatibility Check
            $validCategories = match ($account->account_type) {
                AccountTypeEnum::Asset => [AccountCategoryEnum::CurrentAsset, AccountCategoryEnum::FixedAsset, AccountCategoryEnum::OtherAsset],
                AccountTypeEnum::Liability => [AccountCategoryEnum::CurrentLiability, AccountCategoryEnum::LongTermLiability],
                AccountTypeEnum::Equity => [AccountCategoryEnum::Equity],
                AccountTypeEnum::Revenue => [AccountCategoryEnum::Revenue],
                AccountTypeEnum::CostOfSales => [AccountCategoryEnum::CostOfSales],
                AccountTypeEnum::Expense => [AccountCategoryEnum::Expense],
                AccountTypeEnum::Statistical => [AccountCategoryEnum::Statistical],
            };

            if (!in_array($account->account_category, $validCategories)) {
                throw new \Exception("Account category {$account->account_category->value} is not compatible with account type {$account->account_type->value}.");
            }

            // BR-004 & BR-005: Cash Equivalent Rules
            if ($account->is_cash_equivalent) {
                if ($account->account_type !== AccountTypeEnum::Asset) {
                    throw new \Exception("Only Asset accounts can be cash equivalent.");
                }
            }

            // Immutability Check (BR-007, BR-008)
            if ($account->exists) {
                $checkFields = ['account_type', 'account_category', 'is_cash_equivalent'];
                $isDirty = false;
                foreach ($checkFields as $field) {
                    if ($account->isDirty($field)) {
                        $isDirty = true;
                        break;
                    }
                }

                if ($isDirty) {
                    $hasActivity = \Illuminate\Support\Facades\DB::table('gl_ledger_balances')->where('account_id', $account->id)->exists()
                        || \Illuminate\Support\Facades\DB::table('gl_journal_entry_lines')->where('account_id', $account->id)->exists();

                    if ($hasActivity) {
                        throw new \Exception("Account type, category, and cash equivalent status are immutable after activity exists.");
                    }
                }
            }
        });
    }
}
