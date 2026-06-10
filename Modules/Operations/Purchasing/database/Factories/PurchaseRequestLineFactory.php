<?php

namespace Modules\Operations\Purchasing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\PurchaseRequestLine;

class PurchaseRequestLineFactory extends Factory
{
    protected $model = PurchaseRequestLine::class;

    public function definition(): array
    {
        $qty = $this->faker->randomFloat(3, 1, 100);
        $cost = $this->faker->randomFloat(2, 10, 1000);
        return [
            'purchase_request_id' => PurchaseRequest::factory(),
            'inventory_item_id' => null,
            'description' => $this->faker->words(3, true),
            'quantity' => $qty,
            'unit_id' => (string) \Illuminate\Support\Str::ulid(),
            'estimated_unit_cost' => $cost,
            'estimated_total_cost' => $qty * $cost,
            'remarks' => $this->faker->sentence(),
        ];
    }
}
