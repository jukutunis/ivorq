<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Foundation\Property\Models\Company;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name'      => fake()->company(),
            'slug'      => fake()->unique()->slug(),
            'is_active' => true,
        ];
    }
}
