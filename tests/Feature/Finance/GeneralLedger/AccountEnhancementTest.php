<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;

class AccountEnhancementTest extends TestCase
{
    use RefreshDatabase;

    protected string $propertyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propertyId = (string) Str::ulid();
    }

    protected function createAccount(AccountTypeEnum $type, AccountCategoryEnum $category, bool $isCash = false): Account
    {
        return Account::create([
            'property_id' => $this->propertyId,
            'code' => 'TEST-' . rand(1000, 9999),
            'name' => 'Test Account',
            'normal_balance' => NormalBalanceEnum::Debit,
            'account_type' => $type,
            'account_category' => $category,
            'is_cash_equivalent' => $isCash,
        ]);
    }

    public function test_account_category_becomes_immutable_after_posting()
    {
        $account = $this->createAccount(AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);

        // Can change before posting
        $account->account_category = AccountCategoryEnum::FixedAsset;
        $account->save();
        $this->assertEquals(AccountCategoryEnum::FixedAsset, $account->fresh()->account_category);

        // Post activity
        LedgerBalance::create([
            'property_id' => $this->propertyId,
            'account_id' => $account->id,
            'period_year' => 2026,
            'period_month' => 6,
            'debit_total' => 100,
            'credit_total' => 0,
            'ending_balance' => 0,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('immutable after activity exists');

        $account->account_category = AccountCategoryEnum::OtherAsset;
        $account->save();
    }

    public function test_cash_equivalent_becomes_immutable_after_posting()
    {
        $account = $this->createAccount(AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset, false);

        // Post activity
        LedgerBalance::create([
            'property_id' => $this->propertyId,
            'account_id' => $account->id,
            'period_year' => 2026,
            'period_month' => 6,
            'debit_total' => 100,
            'credit_total' => 0,
            'ending_balance' => 0,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('immutable after activity exists');

        $account->is_cash_equivalent = true;
        $account->save();
    }

    public function test_account_requires_valid_category_compatibility()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('is not compatible with account type');

        $this->createAccount(AccountTypeEnum::Revenue, AccountCategoryEnum::CurrentAsset);
    }

    public function test_only_asset_types_can_be_cash_equivalent()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Only Asset accounts can be cash equivalent.');

        $this->createAccount(AccountTypeEnum::Liability, AccountCategoryEnum::CurrentLiability, true);
    }
}
