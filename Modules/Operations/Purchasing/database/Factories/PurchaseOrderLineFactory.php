<?php

namespace Modules\Operations\Purchasing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;

class PurchaseOrderLineFactory extends Factory
{
    protected $model = PurchaseOrderLine::class;

    public function definition(): array
    {
        return [
            'description' => $this->faker->sentence(),
            'ordered_quantity' => $this->faker->randomFloat(3, 1, 100),
            'received_quantity' => 0,
            'invoiced_quantity' => 0,
            'receiving_tolerance_percent' => 0,
            'unit_cost' => $this->faker->randomFloat(2, 10, 1000),
            'line_total' => function (array $attributes) {
                return $attributes['ordered_quantity'] * $attributes['unit_cost'];
            },
        ];
    }
}
