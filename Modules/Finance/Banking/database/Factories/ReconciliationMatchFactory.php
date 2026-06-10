<?php

namespace Modules\Finance\Banking\database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Finance\Banking\Models\ReconciliationMatch;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Payables\Models\PaymentVoucher;

class ReconciliationMatchFactory extends Factory
{
    protected $model = ReconciliationMatch::class;

    public function definition(): array
    {
        return [
            'reconciliation_session_id' => ReconciliationSession::factory(),
            'bank_statement_line_id' => BankStatementLine::factory(),
            'matchable_type' => PaymentVoucher::class,
            'matchable_id' => (string) \Illuminate\Support\Str::ulid(),
            'amount_matched' => $this->faker->randomFloat(2, 10, 1000),
            'is_locked' => false,
            'matchable_reference' => $this->faker->word,
            'matchable_amount' => $this->faker->randomFloat(2, 10, 1000),
            'statement_reference' => $this->faker->word,
            'statement_amount' => $this->faker->randomFloat(2, 10, 1000),
            'bank_account_balance_before' => $this->faker->randomFloat(2, 1000, 5000),
            'bank_account_balance_after' => $this->faker->randomFloat(2, 1000, 5000),
        ];
    }
}
