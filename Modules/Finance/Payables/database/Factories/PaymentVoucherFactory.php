<?php

namespace Modules\Finance\Payables\database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Finance\Payables\Models\PaymentVoucher;

class PaymentVoucherFactory extends Factory
{
    protected $model = PaymentVoucher::class;

    public function definition(): array
    {
        return [
            'property_id' => (string) \Illuminate\Support\Str::ulid(),
            'vendor_id' => (string) \Illuminate\Support\Str::ulid(),
            'voucher_no' => 'PV-' . $this->faker->unique()->numberBetween(1000, 9999),
            'payment_date' => $this->faker->date(),
            'payment_method' => 'BankTransfer',
            'reference_no' => 'REF-' . $this->faker->numberBetween(1000, 9999),
            'total_amount' => $this->faker->randomFloat(2, 10, 1000),
            'status' => 'Posted',
        ];
    }
}
