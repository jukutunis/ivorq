<?php

namespace Modules\Operations\AssetManagement\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Operations\AssetManagement\Models\AssetType;
use Illuminate\Support\Str;

class AssetTypeFactory extends Factory
{
    protected $model = AssetType::class;

    public function definition(): array
    {
        return [
            'id' => Str::ulid()->toBase32(),
            'property_id' => '01H2',
            'asset_category_id' => Str::ulid()->toBase32(), // Override in test
            'name' => $this->faker->words(2, true),
            'code' => $this->faker->unique()->word(),
            'description' => $this->faker->sentence(),
        ];
    }
}
