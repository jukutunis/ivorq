<?php

namespace Tests\Feature\Operations\Concerns;

use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Zoning\Enums\ZoneAssignmentStatusEnum;
use Modules\Operations\Zoning\Enums\ZoneStatusEnum;
use Modules\Operations\Zoning\Models\Zone;
use Modules\Operations\Zoning\Models\ZoneAssignment;
use Modules\Operations\Zoning\Models\ZoneTemplate;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;

trait CreatesOperationsData
{
    use CreatesFoundationData;

    protected function createZone(Property $property, array $overrides = []): Zone
    {
        static $sequence = 0;
        $sequence++;

        return Zone::create(array_merge([
            'property_id' => $property->id,
            'zone_code'   => "ZN{$sequence}",
            'zone_name'   => "Zone {$sequence}",
            'zone_type'   => 'custom',
            'status'      => ZoneStatusEnum::Draft->value,
            'priority'    => 3,
        ], $overrides));
    }

    protected function createActiveZone(Property $property, array $overrides = []): Zone
    {
        $zone = $this->createZone($property, $overrides);
        $zone->update(['status' => ZoneStatusEnum::Active->value]);

        return $zone->fresh();
    }

    protected function createZoneTemplate(Property $property, array $overrides = []): ZoneTemplate
    {
        static $sequence = 0;
        $sequence++;

        return ZoneTemplate::create(array_merge([
            'property_id'      => $property->id,
            'template_name'    => "Template {$sequence}",
            'zone_type'        => 'custom',
            'default_priority' => 3,
            'is_active'        => true,
        ], $overrides));
    }

    protected function createZoneAssignment(
        Zone       $zone,
        User       $user,
        Department $department,
        array      $overrides = []
    ): ZoneAssignment {
        return ZoneAssignment::create(array_merge([
            'property_id'   => $zone->property_id,
            'zone_id'       => $zone->id,
            'user_id'       => $user->id,
            'department_id' => $department->id,
            'start_date'    => '2026-06-01',
            'end_date'      => '2026-06-30',
            'status'        => ZoneAssignmentStatusEnum::Active->value,
        ], $overrides));
    }

    protected function createManager(Property $property, array $overrides = []): User
    {
        return $this->createUser($property, 'general-manager', $overrides);
    }
}
