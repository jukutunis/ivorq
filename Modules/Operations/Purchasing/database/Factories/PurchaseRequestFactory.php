<?php

namespace Modules\Operations\Purchasing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseRequest;

class PurchaseRequestFactory extends Factory
{
    protected $model = PurchaseRequest::class;

    public function definition(): array
    {
        return [
            'property_id' => (string) \Illuminate\Support\Str::ulid(),
            'request_no' => 'PR-' . $this->faker->unique()->numerify('####'),
            'department_id' => (string) \Illuminate\Support\Str::ulid(),
            'requester_id' => (string) \Illuminate\Support\Str::ulid(),
            'required_date' => now()->addDays(7)->format('Y-m-d'),
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'estimated_total' => $this->faker->randomFloat(2, 1000, 50000),
            'status' => PurchaseRequestStatusEnum::Draft->value,
            'remarks' => $this->faker->sentence(),
        ];
    }
}
