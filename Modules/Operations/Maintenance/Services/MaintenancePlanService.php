<?php

namespace Modules\Operations\Maintenance\Services;

use Modules\Operations\Maintenance\Models\MaintenancePlan;
use Modules\Operations\Maintenance\DTOs\MaintenancePlanDTO;
use Modules\Operations\Maintenance\Events\MaintenancePlanCreated;
use Modules\Operations\AssetManagement\Models\Asset;
use InvalidArgumentException;

class MaintenancePlanService
{
    public function createPlan(MaintenancePlanDTO $dto): MaintenancePlan
    {
        $asset = Asset::find($dto->asset_id);
        
        if (!$asset || in_array($asset->status, ['Disposed', 'Retired'])) {
            throw new InvalidArgumentException("Cannot create PM for retired or disposed asset.");
        }

        $plan = MaintenancePlan::create([
            'property_id' => $dto->property_id,
            'asset_id' => $dto->asset_id,
            'title' => $dto->title,
            'description' => $dto->description,
            'maintenance_type' => $dto->maintenance_type,
            'frequency' => $dto->frequency,
            'next_due_date' => $dto->next_due_date,
            'status' => $dto->status,
            'created_by' => $dto->created_by,
        ]);

        MaintenancePlanCreated::dispatch($plan);

        return $plan;
    }
}
