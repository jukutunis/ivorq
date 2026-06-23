<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Foundation\Property\Models\Property;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

    public function definition(): array
    {
        return [
            'company_id' => CompanyFactory::new(),
            'name'       => fake()->company() . ' Hotel',
            'slug'       => fake()->unique()->slug(),
            'code'       => fake()->unique()->regexify('[A-Z0-9]{5}'),
            'timezone'   => fake()->timezone(),
            'currency'   => fake()->currencyCode(),
            'is_active'  => true,
        ];
    }
}
