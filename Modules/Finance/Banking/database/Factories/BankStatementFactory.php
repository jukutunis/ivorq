<?php

namespace Modules\Finance\Banking\database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Finance\Banking\Models\BankStatement;
use Modules\Finance\Banking\Enums\BankStatementStatusEnum;
use Modules\Finance\Banking\Models\BankAccount;

class BankStatementFactory extends Factory
{
    protected $model = BankStatement::class;

    public function definition(): array
    {
        return [
            'bank_account_id' => BankAccount::factory(),
            'statement_date' => $this->faker->date(),
            'opening_balance' => 0,
            'closing_balance' => 0,
            'imported_closing_balance' => 0,
            'status' => BankStatementStatusEnum::Draft,
        ];
    }
}
