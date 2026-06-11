<?php

namespace Modules\Operations\AssetManagement\Services;

use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\Enums\AssetCriticalityEnum;
use Modules\Operations\AssetManagement\Enums\AssetConditionEnum;

class AssetRiskScoringService
{
    public function calculateScore(Asset $asset): int
    {
        $score = 0;

        // Criticality Weight
        $criticalityWeights = [
            AssetCriticalityEnum::LOW->value => 10,
            AssetCriticalityEnum::MEDIUM->value => 30,
            AssetCriticalityEnum::HIGH->value => 60,
            AssetCriticalityEnum::CRITICAL->value => 90,
            AssetCriticalityEnum::LIFE_SAFETY->value => 100,
        ];

        // Condition Penalty
        $conditionPenalties = [
            AssetConditionEnum::EXCELLENT->value => 0,
            AssetConditionEnum::GOOD->value => 10,
            AssetConditionEnum::FAIR->value => 30,
            AssetConditionEnum::POOR->value => 60,
            AssetConditionEnum::CRITICAL->value => 100,
        ];

        $score += $criticalityWeights[$asset->criticality] ?? 0;
        $score += $conditionPenalties[$asset->condition] ?? 0;

        return min($score, 100);
    }
}
