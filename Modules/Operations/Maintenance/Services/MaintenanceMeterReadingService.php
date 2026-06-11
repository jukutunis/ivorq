<?php

namespace Modules\Operations\Maintenance\Services;

use Modules\Operations\Maintenance\Models\MaintenanceMeterReading;
use Modules\Operations\Maintenance\DTOs\MaintenanceMeterReadingDTO;
use Modules\Operations\Maintenance\Events\MaintenanceMeterReadingLogged;

class MaintenanceMeterReadingService
{
    public function logReading(MaintenanceMeterReadingDTO $dto): MaintenanceMeterReading
    {
        $reading = MaintenanceMeterReading::create([
            'property_id' => $dto->property_id,
            'asset_id' => $dto->asset_id,
            'maintenance_plan_id' => $dto->maintenance_plan_id,
            'meter_type' => $dto->meter_type,
            'reading_value' => $dto->reading_value,
            'reading_date' => $dto->reading_date,
            'read_by' => $dto->read_by,
            'notes' => $dto->notes,
        ]);

        MaintenanceMeterReadingLogged::dispatch($reading);

        return $reading;
    }
}
