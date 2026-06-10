<?php

namespace Modules\Operations\Purchasing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Operations\Purchasing\Models\GoodsReceiptLine;

class GoodsReceiptLineFactory extends Factory
{
    protected $model = GoodsReceiptLine::class;

    public function definition(): array
    {
        return [
            'quantity_received' => $this->faker->randomFloat(3, 1, 100),
            'unit_cost' => $this->faker->randomFloat(2, 10, 1000),
            'line_total' => function (array $attributes) {
                return $attributes['quantity_received'] * $attributes['unit_cost'];
            },
        ];
    }
}
