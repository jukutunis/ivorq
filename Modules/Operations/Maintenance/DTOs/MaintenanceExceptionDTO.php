<?php

namespace Modules\Operations\Maintenance\DTOs;

class MaintenanceExceptionDTO
{
    public function __construct(
        public readonly string $property_id,
        public readonly string $asset_id,
        public readonly string $exception_type,
        public readonly string $status,
        public readonly ?string $maintenance_plan_id = null,
        public readonly ?string $maintenance_execution_id = null,
        public readonly ?string $maintenance_checklist_id = null,
        public readonly ?string $description = null,
        public readonly ?string $reported_by = null,
    ) {}
}
