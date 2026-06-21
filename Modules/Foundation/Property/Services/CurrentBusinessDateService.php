<?php

namespace Modules\Foundation\Property\Services;

use Shared\Services\CurrentPropertyService;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Shared\Exceptions\PropertyNotResolvedException;
use Shared\Exceptions\NotFoundException;
use Shared\Exceptions\BusinessLogicException;
use Illuminate\Database\QueryException;

class CurrentBusinessDateService
{
    public function __construct(
        private CurrentPropertyService $currentPropertyService
    ) {}

    /**
     * Resolves the current active Open Business Date for the active Property.
     */
    public function getActiveBusinessDate(): PropertyBusinessDate
    {
        $propertyId = $this->currentPropertyService->getId();

        if (!$propertyId) {
            throw new PropertyNotResolvedException();
        }

        try {
            $hasHistory = PropertyBusinessDate::where('property_id', $propertyId)->exists();
            if (!$hasHistory) {
                throw new NotFoundException("Business Date record");
            }

            $businessDate = PropertyBusinessDate::where('property_id', $propertyId)
                ->where('status', PropertyBusinessDateStatusEnum::Open->value)
                ->first();

            if (!$businessDate) {
                throw new BusinessLogicException("Business Date history exists but no Open Business Date exists.");
            }

            return $businessDate;
        } catch (QueryException $e) {
            throw new BusinessLogicException("Unexpected persistence failure: " . $e->getMessage());
        }
    }
}
