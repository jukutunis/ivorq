<?php

namespace Modules\Finance\GeneralLedger\Services;

use Carbon\Carbon;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityMappingNotFoundException;

class OperationalIdentityMappingService
{
    public function resolve(
        string $propertyId,
        OperationalIdentityEnum $identity,
        Carbon $date,
        ?string $costCenterId = null
    ): OperationalIdentityMapping {
        
        $dateString = $date->toDateString();

        // Step 1: Try exact match
        $exactMatch = OperationalIdentityMapping::where('property_id', $propertyId)
            ->where('operational_identity', $identity->value)
            ->where('is_active', true)
            ->where('effective_from', '<=', $dateString)
            ->where(function ($query) use ($dateString) {
                $query->whereNull('effective_to')
                      ->orWhere('effective_to', '>=', $dateString);
            });

        // Apply cost_center_id explicitly
        if ($costCenterId) {
            $exactMatch->where('cost_center_id', $costCenterId);
        } else {
            $exactMatch->whereNull('cost_center_id');
        }

        $mapping = $exactMatch->first();

        if ($mapping) {
            return $mapping;
        }

        // Step 2: Try fallback match (cost_center_id = null)
        if ($costCenterId !== null) {
            $fallbackMatch = OperationalIdentityMapping::where('property_id', $propertyId)
                ->where('operational_identity', $identity->value)
                ->where('is_active', true)
                ->where('effective_from', '<=', $dateString)
                ->where(function ($query) use ($dateString) {
                    $query->whereNull('effective_to')
                          ->orWhere('effective_to', '>=', $dateString);
                })
                ->whereNull('cost_center_id')
                ->first();

            if ($fallbackMatch) {
                return $fallbackMatch;
            }
        }

        // Step 3: Not found
        throw new OperationalIdentityMappingNotFoundException(
            "Mapping not found for identity: {$identity->value}, property: {$propertyId}, date: {$dateString}, cost center: {$costCenterId}"
        );
    }
}
