<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Tests\TestCase;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityValidationService;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityValidationException;

class OperationalIdentityValidationTest extends TestCase
{
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OperationalIdentityValidationService();
    }

    private function makeAccount(AccountTypeEnum $type): Account
    {
        $account = new Account();
        $account->code = 'TEST';
        $account->account_type = $type;
        return $account;
    }

    public function test_valid_asset_mapping()
    {
        $account = $this->makeAccount(AccountTypeEnum::Asset);
        
        $this->service->validate(OperationalIdentityEnum::INVENTORY, $account);
        $this->service->validate(OperationalIdentityEnum::VENDOR_PREPAYMENT, $account);
        $this->service->validate(OperationalIdentityEnum::CASH_AND_BANK, $account);
        $this->service->validate(OperationalIdentityEnum::AP_DEBIT_NOTE_RECEIVABLE, $account);
        
        // No exception thrown means it passed
        $this->assertTrue(true);
    }

    public function test_valid_liability_mapping()
    {
        $account = $this->makeAccount(AccountTypeEnum::Liability);
        
        $this->service->validate(OperationalIdentityEnum::GRNI_ACCRUAL, $account);
        $this->service->validate(OperationalIdentityEnum::AP_CONTROL, $account);
        $this->service->validate(OperationalIdentityEnum::ACCRUED_EXPENSE, $account);
        
        $this->assertTrue(true);
    }

    public function test_valid_expense_mapping()
    {
        $account = $this->makeAccount(AccountTypeEnum::Expense);
        
        $this->service->validate(OperationalIdentityEnum::COST_OF_CONSUMPTION, $account);
        $this->service->validate(OperationalIdentityEnum::OPERATIONAL_EXPENSE, $account);
        $this->service->validate(OperationalIdentityEnum::AP_INVOICE_VARIANCE, $account);
        $this->service->validate(OperationalIdentityEnum::INVENTORY_ADJUSTMENT_LOSS, $account);
        
        $this->assertTrue(true);
    }

    public function test_valid_income_mapping()
    {
        $account = $this->makeAccount(AccountTypeEnum::Revenue);
        
        $this->service->validate(OperationalIdentityEnum::INVENTORY_ADJUSTMENT_GAIN, $account);
        $this->service->validate(OperationalIdentityEnum::AP_CREDIT_NOTE_GAIN, $account);
        $this->service->validate(OperationalIdentityEnum::AP_WRITEOFF_GAIN, $account);
        
        $this->assertTrue(true);
    }

    public function test_invalid_asset_mapping()
    {
        $this->expectException(OperationalIdentityValidationException::class);
        $this->expectExceptionMessage("Invalid mapping configuration");
        
        $account = $this->makeAccount(AccountTypeEnum::Revenue); // Invalid for INVENTORY
        $this->service->validate(OperationalIdentityEnum::INVENTORY, $account);
    }

    public function test_invalid_liability_mapping()
    {
        $this->expectException(OperationalIdentityValidationException::class);
        
        $account = $this->makeAccount(AccountTypeEnum::Asset); // Invalid for AP_CONTROL
        $this->service->validate(OperationalIdentityEnum::AP_CONTROL, $account);
    }

    public function test_invalid_expense_mapping()
    {
        $this->expectException(OperationalIdentityValidationException::class);
        
        $account = $this->makeAccount(AccountTypeEnum::Liability); // Invalid for COST_OF_CONSUMPTION
        $this->service->validate(OperationalIdentityEnum::COST_OF_CONSUMPTION, $account);
    }

    public function test_invalid_income_mapping()
    {
        $this->expectException(OperationalIdentityValidationException::class);
        
        $account = $this->makeAccount(AccountTypeEnum::Expense); // Invalid for INVENTORY_ADJUSTMENT_GAIN
        $this->service->validate(OperationalIdentityEnum::INVENTORY_ADJUSTMENT_GAIN, $account);
    }
    
    public function test_memo_control_mapping()
    {
        $account = $this->makeAccount(AccountTypeEnum::Statistical);
        
        $this->service->validate(OperationalIdentityEnum::PURCHASE_COMMITMENT, $account);
        $this->service->validate(OperationalIdentityEnum::BUDGET_RESERVE, $account);
        
        $this->assertTrue(true);
    }
}
