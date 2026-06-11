<?php

namespace Modules\Operations\Maintenance\DTOs;

class MaintenanceMeterReadingDTO
{
    public function __construct(
        public readonly string $property_id,
        public readonly string $asset_id,
        public readonly string $meter_type,
        public readonly float $reading_value,
        public readonly string $reading_date,
        public readonly ?string $maintenance_plan_id = null,
        public readonly ?string $read_by = null,
        public readonly ?string $notes = null,
    ) {}
}
