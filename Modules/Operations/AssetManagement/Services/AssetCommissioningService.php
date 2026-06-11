<?php

namespace Modules\Operations\AssetManagement\Services;

use Modules\Operations\AssetManagement\Models\AssetCommissioning;
use Modules\Operations\AssetManagement\DTOs\AssetCommissioningDTO;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\Enums\AssetStatusEnum;

class AssetCommissioningService
{
    public function executeCommissioning(AssetCommissioningDTO $dto): AssetCommissioning
    {
        $commissioning = AssetCommissioning::create([
            'property_id' => $dto->property_id,
            'asset_id' => $dto->asset_id,
            'status' => $dto->status,
            'acceptance_test_date' => $dto->acceptance_test_date,
            'vendor_signoff_user_id' => $dto->vendor_signoff_user_id,
            'engineer_signoff_user_id' => $dto->engineer_signoff_user_id,
            'notes' => $dto->notes,
        ]);

        if ($dto->status === 'Approved') {
            $asset = Asset::findOrFail($dto->asset_id);
            $asset->status = AssetStatusEnum::COMMISSIONED->value;
            $asset->commissioning_date = now();
            $asset->save();
        }

        return $commissioning;
    }
}
