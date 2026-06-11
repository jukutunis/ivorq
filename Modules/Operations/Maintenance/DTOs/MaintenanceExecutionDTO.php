<?php

namespace Modules\Operations\Maintenance\DTOs;

class MaintenanceExecutionDTO
{
    public function __construct(
        public readonly string $property_id,
        public readonly string $maintenance_plan_id,
        public readonly string $asset_id,
        public readonly string $status,
        public readonly string $scheduled_date,
        public readonly ?string $executed_date = null,
        public readonly ?string $executed_by = null,
        public readonly ?array $checklist_snapshot = null,
        public readonly ?string $notes = null,
    ) {}
}
