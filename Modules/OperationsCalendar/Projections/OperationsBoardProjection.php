<?php

namespace Modules\OperationsCalendar\Projections;

use Illuminate\Support\Collection;

class OperationsBoardProjection
{
    public function project(Collection $items): Collection
    {
        // The operations board usually wants to see what's happening today/now
        // Grouped by date, then ordered by start time
        return $items->groupBy(function ($item) {
            return $item->start_datetime->format('Y-m-d');
        })->map(function ($dayItems) {
            return $dayItems->sortBy(fn($i) => $i->start_datetime->timestamp)->values();
        });
    }
}
