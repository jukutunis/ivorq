<?php

namespace Modules\Operations\Maintenance\Services;

use Modules\Operations\Maintenance\Models\MaintenanceException;
use Modules\Operations\Maintenance\DTOs\MaintenanceExceptionDTO;
use Modules\Operations\Maintenance\Events\MaintenanceExceptionCreated;

class MaintenanceExceptionService
{
    public function logException(MaintenanceExceptionDTO $dto): MaintenanceException
    {
        $exception = MaintenanceException::create([
            'property_id' => $dto->property_id,
            'asset_id' => $dto->asset_id,
            'maintenance_plan_id' => $dto->maintenance_plan_id,
            'maintenance_execution_id' => $dto->maintenance_execution_id,
            'maintenance_checklist_id' => $dto->maintenance_checklist_id,
            'exception_type' => $dto->exception_type,
            'description' => $dto->description,
            'status' => $dto->status,
            'reported_by' => $dto->reported_by,
        ]);

        MaintenanceExceptionCreated::dispatch($exception);

        return $exception;
    }
}
