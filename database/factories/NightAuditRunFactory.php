<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Modules\Operations\NightAudit\Models\NightAuditRun;

class NightAuditRunFactory extends Factory
{
    protected $model = NightAuditRun::class;

    public function definition(): array
    {
        $property = \Database\Factories\PropertyFactory::new()->create([
            'timezone' => 'UTC',
            'currency' => 'USD',
        ]);
        $actor = \Database\Factories\UserFactory::new()->withProperty($property)->create(['is_active' => true]);
        $businessDate = PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => today('UTC')->toDateString(),
            'timezone_snapshot' => $property->timezone,
            'opened_by' => $actor->id,
            'opened_at' => now('UTC'),
        ]);

        return [
            'property_id' => $property->id,
            'property_business_date_id' => $businessDate->id,
            'business_date_snapshot' => $businessDate->business_date->format('Y-m-d'),
            'property_timezone_snapshot' => $property->timezone,
            'attempt_number' => 1,
            'status' => NightAuditRunStatusEnum::InProgress,
            'started_by' => $actor->id,
            'started_at' => now('UTC'),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ];
    }

    public function forContext(Property $property, PropertyBusinessDate $businessDate, User $actor): static
    {
        return $this->state(fn () => [
            'property_id' => $property->id,
            'property_business_date_id' => $businessDate->id,
            'business_date_snapshot' => $businessDate->business_date->format('Y-m-d'),
            'property_timezone_snapshot' => (string) $businessDate->timezone_snapshot,
            'started_by' => $actor->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    public function aborted(?User $actor = null, ?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => NightAuditRunStatusEnum::Aborted,
            'aborted_by' => $actor?->id ?? ($attributes['started_by'] ?? null),
            'aborted_at' => now('UTC')->addMinute(),
            'abort_reason' => $reason ?? 'Operational abort recorded for testing.',
        ]);
    }
}
