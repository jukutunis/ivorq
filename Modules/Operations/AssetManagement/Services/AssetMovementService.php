<?php

namespace Modules\Operations\AssetManagement\Services;

use Exception;
use Modules\Operations\AssetManagement\Models\AssetMovement;
use Modules\Operations\AssetManagement\DTOs\AssetMovementDTO;
use Modules\Operations\AssetManagement\Models\Asset;

class AssetMovementService
{
    public function recordMovement(AssetMovementDTO $dto): AssetMovement
    {
        $asset = Asset::findOrFail($dto->asset_id);
        
        // Cannot move disposed
        if ($asset->status === 'Disposed') {
            throw new Exception("Disposed assets cannot be moved.");
        }

        $movement = AssetMovement::create([
            'property_id' => $dto->property_id,
            'asset_id' => $dto->asset_id,
            'movement_type' => $dto->movement_type,
            'from_location_id' => $dto->from_location_id,
            'to_location_id' => $dto->to_location_id,
            'from_department_id' => $dto->from_department_id,
            'to_department_id' => $dto->to_department_id,
            'user_id' => $dto->user_id,
            'movement_date' => $dto->movement_date,
            'reason' => $dto->reason,
        ]);

        // Update asset current location
        if ($dto->to_location_id) {
            $asset->location_id = $dto->to_location_id;
        }
        if ($dto->to_department_id) {
            $asset->department_id = $dto->to_department_id;
        }
        $asset->save();

        return $movement;
    }
}
