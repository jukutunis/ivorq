<?php

namespace Modules\Operations\WorkOrder\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Modules\Foundation\Property\Models\Property;

class WorkOrderFactory extends Factory
{
    protected $model = WorkOrder::class;

    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'wo_number' => 'WO-' . $this->faker->unique()->numberBetween(1000, 9999),
            'title' => $this->faker->sentence,
            'status' => 'draft',
            'priority' => 'low',
            'type' => 'corrective',
        ];
    }
}
