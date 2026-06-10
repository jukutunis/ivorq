<?php

namespace Modules\Finance\Banking\database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Modules\Finance\Banking\Models\BankAccount;

class ReconciliationSessionFactory extends Factory
{
    protected $model = ReconciliationSession::class;

    public function definition(): array
    {
        return [
            'bank_account_id' => BankAccount::factory(),
            'statement_date_start' => $this->faker->date(),
            'statement_date_end' => $this->faker->date(),
            'opening_balance' => 0,
            'reconciled_balance' => 0,
            'unreconciled_balance' => 0,
            'status' => ReconciliationSessionStatusEnum::Open,
        ];
    }
}
