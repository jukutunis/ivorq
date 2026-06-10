<?php

namespace Modules\Operations\Purchasing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseOrder;

class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            'po_no' => 'PO-' . now()->format('Y') . '-' . str_pad($this->faker->unique()->randomNumber(6), 6, '0', STR_PAD_LEFT),
            'issue_date' => now(),
            'expected_delivery_date' => now()->addDays(7),
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'subtotal' => $this->faker->randomFloat(2, 1000, 100000),
            'tax_amount' => 0,
            'total_amount' => function (array $attributes) {
                return $attributes['subtotal'] + $attributes['tax_amount'];
            },
            'status' => PurchaseOrderStatusEnum::Draft->value,
            'remarks' => $this->faker->sentence(),
        ];
    }
}
