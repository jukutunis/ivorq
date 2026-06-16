<?php

namespace Modules\OperationsCalendar\Projections;

use Illuminate\Support\Collection;

class CalendarConflictProjection
{
    public function project(Collection $items): Collection
    {
        $conflicts = collect();

        // Group by venue to only check conflicts within the same space
        // Note: For complex combinations, this relies on FunctionSpaceBooking 
        // to have already created shadow bookings or the source data to reflect it.
        $byVenue = $items->filter(fn($i) => $i->venue_id !== null)->groupBy('venue_id');

        foreach ($byVenue as $venueId => $venueItems) {
            $sorted = $venueItems->sortBy(fn($i) => $i->start_datetime->timestamp)->values();
            
            for ($i = 0; $i < $sorted->count(); $i++) {
                for ($j = $i + 1; $j < $sorted->count(); $j++) {
                    $itemA = $sorted[$i];
                    $itemB = $sorted[$j];

                    // Since it's sorted by start_datetime, if B starts after A ends, no more conflicts for A
                    if ($itemB->start_datetime->gte($itemA->end_datetime)) {
                        break;
                    }

                    // Conflict found
                    $conflicts->push([
                        'item_a' => $itemA,
                        'item_b' => $itemB,
                        'venue_id' => $venueId,
                        'overlap_start' => $itemA->start_datetime->max($itemB->start_datetime),
                        'overlap_end' => $itemA->end_datetime->min($itemB->end_datetime),
                    ]);
                }
            }
        }

        return $conflicts;
    }
}
