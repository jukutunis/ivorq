<?php

namespace Modules\Operations\AssetManagement\Services;

use Exception;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\Enums\AssetStatusEnum;

class AssetLifecycleService
{
    public function transitionStatus(Asset $asset, AssetStatusEnum $newStatus): Asset
    {
        // Business Rules
        if ($asset->status === AssetStatusEnum::DISPOSED->value) {
            throw new Exception("Disposed assets cannot be edited or transitioned.");
        }

        if ($newStatus === AssetStatusEnum::ACTIVE && $asset->status !== AssetStatusEnum::COMMISSIONED->value && $asset->status !== AssetStatusEnum::UNDER_MAINTENANCE->value) {
            throw new Exception("Asset must be Commissioned before it can become Active.");
        }

        $asset->status = $newStatus->value;
        $asset->save();

        return $asset;
    }
}
