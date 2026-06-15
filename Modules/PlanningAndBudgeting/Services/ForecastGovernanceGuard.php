<?php

namespace Modules\PlanningAndBudgeting\Services;

use Exception;
use Modules\PlanningAndBudgeting\Models\Forecast;
use Modules\PlanningAndBudgeting\Models\ForecastVersion;
use Modules\PlanningAndBudgeting\Enums\ForecastStatusEnum;

class ForecastGovernanceGuard
{
    /**
     * Enforce Property Isolation Rules
     */
    public function validatePropertyAccess(Forecast $forecast, string $userCompanyId, string $userPropertyId): void
    {
        if ($forecast->company_id !== $userCompanyId || $forecast->property_id !== $userPropertyId) {
            throw new Exception("GovernanceException: Cross-property or cross-company forecast access is forbidden.");
        }
    }

    /**
     * Enforce Forecast Locking and Immutability
     */
    public function validateVersionModification(ForecastVersion $version): void
    {
        if ($version->status === ForecastStatusEnum::Locked) {
            throw new Exception("GovernanceException: Cannot modify a LOCKED forecast version. Version is immutable.");
        }
    }

    /**
     * Lock a Forecast Version
     */
    public function lockVersion(ForecastVersion $version, string $userId): void
    {
        if ($version->status === ForecastStatusEnum::Locked) {
            throw new Exception("GovernanceException: Version is already locked.");
        }

        $version->update([
            'status' => ForecastStatusEnum::Locked,
            'updated_by' => $userId,
        ]);
    }
}
