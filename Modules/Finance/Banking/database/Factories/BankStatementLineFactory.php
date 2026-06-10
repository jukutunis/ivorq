<?php

namespace Modules\Finance\Banking\database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Banking\Models\BankStatement;

class BankStatementLineFactory extends Factory
{
    protected $model = BankStatementLine::class;

    public function definition(): array
    {
        return [
            'bank_statement_id' => BankStatement::factory(),
            'transaction_date' => $this->faker->date(),
            'description' => $this->faker->sentence(),
            'reference' => $this->faker->regexify('[A-Z0-9]{10}'),
            'amount' => $this->faker->randomFloat(2, -1000, 1000),
            'is_reconciled' => false,
        ];
    }
}
