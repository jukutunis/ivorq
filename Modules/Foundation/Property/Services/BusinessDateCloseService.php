<?php

namespace Modules\Foundation\Property\Services;

use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class BusinessDateCloseService
{
    public function closeCurrentBusinessDate(): PropertyBusinessDate
    {
        $user = Auth::user();

        if (!$user) {
            throw new RuntimeException("Missing authenticated actor context. Cannot close Business Date.");
        }

        // Must resolve from session as per established ActivePropertyContext
        $propertyId = request()->session()->get('active_property_id');

        if (!$propertyId) {
            throw new RuntimeException("Missing active property context. Cannot close Business Date.");
        }

        return DB::transaction(function () use ($propertyId, $user) {
            $businessDate = PropertyBusinessDate::where('property_id', $propertyId)
                ->where('status', PropertyBusinessDateStatusEnum::Open)
                ->where('is_open', true)
                ->lockForUpdate()
                ->first();

            if (!$businessDate) {
                throw new RuntimeException("No open business date found to close.");
            }

            // Post-lock revalidation
            if ($businessDate->status !== PropertyBusinessDateStatusEnum::Open || !$businessDate->is_open) {
                throw new RuntimeException("Business date is not open.");
            }

            $businessDate->status = PropertyBusinessDateStatusEnum::Closed;
            $businessDate->is_open = null;
            $businessDate->closed_at = now();
            $businessDate->closed_by = $user->id;

            $businessDate->save();

            return $businessDate;
        });
    }
}
