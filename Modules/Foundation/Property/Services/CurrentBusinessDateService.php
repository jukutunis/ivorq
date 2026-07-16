<?php

namespace Modules\Foundation\Property\Services;

use Shared\Services\CurrentPropertyService;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Shared\Exceptions\PropertyNotResolvedException;
use Illuminate\Database\QueryException;
use RuntimeException;

class CurrentBusinessDateService
{
    public const ERROR_NOT_INITIALIZED = 'BD_A1_BUSINESS_DATE_NOT_INITIALIZED';
    public const ERROR_OPEN_UNAVAILABLE = 'BD_A1_OPEN_BUSINESS_DATE_UNAVAILABLE';
    public const ERROR_MULTIPLE_OPEN = 'BD_A1_MULTIPLE_OPEN_BUSINESS_DATES';

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
                throw new RuntimeException(self::ERROR_NOT_INITIALIZED);
            }

            $openDates = PropertyBusinessDate::where('property_id', $propertyId)
                ->where('status', PropertyBusinessDateStatusEnum::Open->value)
                ->where('is_open', true)
                ->orderBy('business_date')
                ->limit(2)
                ->get();

            if ($openDates->count() === 0) {
                throw new RuntimeException(self::ERROR_OPEN_UNAVAILABLE);
            }

            if ($openDates->count() > 1) {
                throw new RuntimeException(self::ERROR_MULTIPLE_OPEN);
            }

            return $openDates->first();
        } catch (QueryException $e) {
            throw new RuntimeException(self::ERROR_OPEN_UNAVAILABLE, 0, $e);
        }
    }
}
