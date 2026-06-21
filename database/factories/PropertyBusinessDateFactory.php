<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Carbon\Carbon;

class PropertyBusinessDateFactory extends Factory
{
    protected $model = PropertyBusinessDate::class;

    public function definition(): array
    {
        return [
            'property_id' => \Database\Factories\PropertyFactory::new(),
            'business_date' => Carbon::today(),
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'opened_at' => now(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PropertyBusinessDateStatusEnum::Closed,
            'is_open' => null,
            'closed_at' => now(),
        ]);
    }
}
