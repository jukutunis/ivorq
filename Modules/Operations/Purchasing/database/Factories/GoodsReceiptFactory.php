<?php

namespace Modules\Operations\Purchasing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Operations\Purchasing\Models\GoodsReceipt;
use Modules\Operations\Purchasing\Enums\GoodsReceiptStatusEnum;

class GoodsReceiptFactory extends Factory
{
    protected $model = GoodsReceipt::class;

    public function definition(): array
    {
        return [
            'grn_no' => 'GRN-' . now()->format('Y') . '-' . str_pad($this->faker->unique()->randomNumber(6), 6, '0', STR_PAD_LEFT),
            'received_date' => now(),
            'status' => GoodsReceiptStatusEnum::Posted->value,
            'remarks' => $this->faker->sentence(),
        ];
    }
}
