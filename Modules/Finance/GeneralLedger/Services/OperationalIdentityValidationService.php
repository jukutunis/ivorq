<?php

namespace Modules\Finance\GeneralLedger\Services;

use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityValidationException;

class OperationalIdentityValidationService
{
    /**
     * Define the matrix of allowed account types for each identity.
     * 
     * @return array<string, array<AccountTypeEnum>>
     */
    protected function getValidationMatrix(): array
    {
        return [
            // ASSET
            OperationalIdentityEnum::INVENTORY->value => [AccountTypeEnum::Asset],
            OperationalIdentityEnum::VENDOR_PREPAYMENT->value => [AccountTypeEnum::Asset],
            OperationalIdentityEnum::CASH_AND_BANK->value => [AccountTypeEnum::Asset],
            OperationalIdentityEnum::AP_DEBIT_NOTE_RECEIVABLE->value => [AccountTypeEnum::Asset],
            OperationalIdentityEnum::VENDOR_TAX->value => [AccountTypeEnum::Asset, AccountTypeEnum::Liability],

            // LIABILITY
            OperationalIdentityEnum::GRNI_ACCRUAL->value => [AccountTypeEnum::Liability],
            OperationalIdentityEnum::GRNI_RECEIPT->value => [AccountTypeEnum::Liability],
            OperationalIdentityEnum::GRNI_ACCRUAL_REVISED->value => [AccountTypeEnum::Liability],
            OperationalIdentityEnum::AP_CONTROL->value => [AccountTypeEnum::Liability],
            OperationalIdentityEnum::ACCRUED_EXPENSE->value => [AccountTypeEnum::Liability],

            // MEMO / CONTROL (Statistical)
            OperationalIdentityEnum::PURCHASE_COMMITMENT->value => [AccountTypeEnum::Statistical],
            OperationalIdentityEnum::BUDGET_RESERVE->value => [AccountTypeEnum::Statistical],

            // EXPENSE
            OperationalIdentityEnum::COST_OF_CONSUMPTION->value => [AccountTypeEnum::Expense, AccountTypeEnum::CostOfSales],
            OperationalIdentityEnum::OPERATIONAL_EXPENSE->value => [AccountTypeEnum::Expense],
            OperationalIdentityEnum::AP_INVOICE_VARIANCE->value => [AccountTypeEnum::Expense],
            OperationalIdentityEnum::INVENTORY_ADJUSTMENT_LOSS->value => [AccountTypeEnum::Expense],
            OperationalIdentityEnum::INVENTORY_WRITEOFF_LOSS->value => [AccountTypeEnum::Expense],

            // OTHER_INCOME (Revenue)
            OperationalIdentityEnum::INVENTORY_ADJUSTMENT_GAIN->value => [AccountTypeEnum::Revenue],
            OperationalIdentityEnum::AP_CREDIT_NOTE_GAIN->value => [AccountTypeEnum::Revenue],
            OperationalIdentityEnum::AP_WRITEOFF_GAIN->value => [AccountTypeEnum::Revenue],
        ];
    }

    /**
     * Validate that a resolved account matches expected classification for the operational identity.
     *
     * @param OperationalIdentityEnum $identity
     * @param Account $account
     * @return void
     * @throws OperationalIdentityValidationException
     */
    public function validate(OperationalIdentityEnum $identity, Account $account): void
    {
        $matrix = $this->getValidationMatrix();

        if (!isset($matrix[$identity->value])) {
            throw new OperationalIdentityValidationException(
                "Operational identity {$identity->value} does not have a defined validation rule in the matrix."
            );
        }

        $allowedTypes = $matrix[$identity->value];

        if (!in_array($account->account_type, $allowedTypes, true)) {
            $allowedList = implode(', ', array_map(fn($t) => $t->value, $allowedTypes));
            throw new OperationalIdentityValidationException(
                "Invalid mapping configuration. Identity '{$identity->value}' MUST map to one of: [{$allowedList}]. " . 
                "Resolved account '{$account->code}' is classified as '{$account->account_type->value}'."
            );
        }
    }
}
