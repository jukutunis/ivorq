<?php

namespace Modules\Finance\Payables\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Finance\Payables\Enums\AccountPayableStatusEnum;
use Modules\Finance\Payables\Models\AccountPayable;

class AccountPayableFactory extends Factory
{
    protected $model = AccountPayable::class;

    public function definition(): array
    {
        return [
            'payable_no' => 'AP-' . date('Y') . '-' . str_pad($this->faker->unique()->randomNumber(6), 6, '0', STR_PAD_LEFT),
            'invoice_date' => $this->faker->date(),
            'due_date' => $this->faker->date(),
            'currency_code' => 'IDR',
            'exchange_rate' => 1.0000,
            'amount' => 1000.00,
            'outstanding_amount' => 1000.00,
            'status' => AccountPayableStatusEnum::Open->value,
        ];
    }
}
