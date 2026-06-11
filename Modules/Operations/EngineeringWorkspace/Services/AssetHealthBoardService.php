<?php

namespace Modules\Operations\EngineeringWorkspace\Services;

use Modules\Foundation\User\Models\User;

class AssetHealthBoardService
{
    public function getAssetHealthBoard(User $user): array
    {
        return [
            'high_risk_assets' => 0,
            'warranty_expiring' => 0,
            'frequent_failures' => 0,
            'critical_assets' => [],
        ];
    }
}
