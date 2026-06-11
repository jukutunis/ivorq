<?php

namespace Modules\Operations\AssetManagement\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Operations\AssetManagement\Models\AssetCategory;
use Illuminate\Support\Str;

class AssetCategoryFactory extends Factory
{
    protected $model = AssetCategory::class;

    public function definition(): array
    {
        return [
            'id' => Str::ulid()->toBase32(),
            'property_id' => '01H2',
            'name' => $this->faker->words(2, true),
            'code' => $this->faker->unique()->word(),
            'description' => $this->faker->sentence(),
        ];
    }
}
