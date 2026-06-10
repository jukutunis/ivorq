<?php

namespace Modules\Operations\Purchasing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Foundation\Property\Models\Property;

class VendorCategoryFactory extends Factory
{
    protected $model = VendorCategory::class;

    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'category_code' => 'VC-' . $this->faker->unique()->numerify('####'),
            'name' => $this->faker->companySuffix() . ' Supplies',
            'description' => $this->faker->sentence(),
            'is_active' => true,
        ];
    }
}
