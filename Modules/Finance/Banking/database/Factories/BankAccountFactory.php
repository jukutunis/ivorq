<?php

namespace Modules\Finance\Banking\database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Finance\Banking\Models\BankAccount;

class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        return [
            'bank_name' => $this->faker->company() . ' Bank',
            'account_name' => $this->faker->name(),
            'account_number' => $this->faker->unique()->bankAccountNumber(),
            'currency_code' => 'IDR',
            'opening_balance' => 0,
            'current_balance' => 0,
            'reconciled_balance' => 0,
            'is_active' => true,
        ];
    }
}
