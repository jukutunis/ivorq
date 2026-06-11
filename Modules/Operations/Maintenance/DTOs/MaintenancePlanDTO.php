<?php

namespace Modules\Operations\Maintenance\DTOs;

class MaintenancePlanDTO
{
    public function __construct(
        public readonly string $property_id,
        public readonly string $asset_id,
        public readonly string $title,
        public readonly string $maintenance_type,
        public readonly string $status,
        public readonly ?string $description = null,
        public readonly ?string $frequency = null,
        public readonly ?string $next_due_date = null,
        public readonly ?string $created_by = null,
    ) {}
}
