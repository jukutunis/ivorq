<?php

namespace Modules\FunctionSpace\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\FunctionSpace\Models\Venue;
use Modules\FunctionSpace\Models\FunctionSpaceBooking;
use Modules\FunctionSpace\Models\VenueMaintenanceBlock;
use Modules\FunctionSpace\Enums\FunctionSpaceBookingStatusEnum;

class FunctionSpaceAvailabilityEngine
{
    /**
     * Check if a specific venue is available for a given time range.
     */
    public function isVenueAvailable(Venue $venue, Carbon $startDatetime, Carbon $endDatetime): bool
    {
        $venuesToCheck = $this->getRelatedVenuesForAvailabilityCheck($venue);
        $venueIds = $venuesToCheck->pluck('id')->toArray();

        // Calculate padded times based on turnaround
        $paddedStart = $startDatetime->copy()->subMinutes($venue->default_turnaround_minutes);
        $paddedEnd = $endDatetime->copy()->addMinutes($venue->default_turnaround_minutes);

        $hasOverlappingBookings = FunctionSpaceBooking::whereIn('venue_id', $venueIds)
            ->where('status', '!=', FunctionSpaceBookingStatusEnum::Cancelled)
            ->where(function ($query) use ($paddedStart, $paddedEnd) {
                $query->where(function ($q) use ($paddedStart, $paddedEnd) {
                    $q->where('start_datetime', '<', $paddedEnd)
                      ->where('end_datetime', '>', $paddedStart);
                });
            })
            ->exists();

        if ($hasOverlappingBookings) {
            return false;
        }

        $hasMaintenanceBlocks = VenueMaintenanceBlock::whereIn('venue_id', $venueIds)
            ->where(function ($query) use ($paddedStart, $paddedEnd) {
                $query->where('start_datetime', '<', $paddedEnd)
                      ->where('end_datetime', '>', $paddedStart);
            })
            ->exists();

        if ($hasMaintenanceBlocks) {
            return false;
        }

        return true;
    }

    /**
     * Search available venues for a property based on date range and optional capacity.
     */
    public function searchAvailableVenues(string $propertyId, Carbon $startDatetime, Carbon $endDatetime, ?int $requiredCapacity = null, ?string $setupStyleId = null): Collection
    {
        $query = Venue::where('property_id', $propertyId)
            ->where('is_active', true)
            ->where('status', '!=', 'OUT_OF_ORDER')
            ->where('status', '!=', 'UNDER_RENOVATION');

        if ($requiredCapacity && $setupStyleId) {
            $query->whereHas('capacities', function ($q) use ($requiredCapacity, $setupStyleId) {
                $q->where('setup_style_id', $setupStyleId)
                  ->where('maximum_capacity', '>=', $requiredCapacity);
            });
        }

        $venues = $query->get();

        return $venues->filter(function (Venue $venue) use ($startDatetime, $endDatetime) {
            return $this->isVenueAvailable($venue, $startDatetime, $endDatetime);
        })->values();
    }

    /**
     * Get all venues that must be checked when booking a specific venue.
     * This includes the venue itself, all its parents (recursively), and all its children (recursively).
     */
    protected function getRelatedVenuesForAvailabilityCheck(Venue $venue): Collection
    {
        $related = collect([$venue]);

        // Add parents (venues where this venue is a child in combinations)
        $parentIds = \Modules\FunctionSpace\Models\VenueCombination::where('child_venue_id', $venue->id)->pluck('parent_venue_id');
        $parents = Venue::whereIn('id', $parentIds)->get();
        
        // Add children (venues where this venue is a parent in combinations)
        $childrenIds = \Modules\FunctionSpace\Models\VenueCombination::where('parent_venue_id', $venue->id)->pluck('child_venue_id');
        $children = Venue::whereIn('id', $childrenIds)->get();

        $related = $related->merge($parents)->merge($children);

        return $related->unique('id');
    }
}
