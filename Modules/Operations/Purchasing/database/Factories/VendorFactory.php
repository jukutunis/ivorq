<?php

namespace Modules\Operations\Purchasing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Foundation\Property\Models\Property;

class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'company_id' => null,
            'vendor_category_id' => VendorCategory::factory(),
            'vendor_code' => 'VND-' . $this->faker->unique()->numerify('####'),
            'name' => $this->faker->company(),
            'tax_id' => $this->faker->numerify('##.###.###.#-###.###'),
            'tax_number' => $this->faker->numerify('TAX-####-####'),
            'contact_person' => $this->faker->name(),
            'email' => $this->faker->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'payment_term_days' => $this->faker->randomElement([0, 15, 30, 45, 60]),
            'credit_limit' => $this->faker->randomFloat(2, 1000, 50000),
            'default_currency_code' => 'IDR',
            'is_active' => true,
            'is_approved' => true,
            'performance_score' => $this->faker->randomFloat(2, 60, 100),
        ];
    }
}
