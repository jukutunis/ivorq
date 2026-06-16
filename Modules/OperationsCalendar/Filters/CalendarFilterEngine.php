<?php

namespace Modules\OperationsCalendar\Filters;

use Illuminate\Support\Collection;

class CalendarFilterEngine
{
    public function apply(Collection $items, array $filters): Collection
    {
        return $items->filter(function ($item) use ($filters) {
            if (isset($filters['property_id']) && $item->property_id !== $filters['property_id']) {
                return false;
            }

            if (isset($filters['venue_id']) && $item->venue_id !== $filters['venue_id']) {
                return false;
            }

            if (isset($filters['source_type'])) {
                $filterType = $filters['source_type'] instanceof \BackedEnum 
                    ? $filters['source_type']->value 
                    : $filters['source_type'];
                    
                if ($item->source_type->value !== $filterType) {
                    return false;
                }
            }

            if (isset($filters['status']) && $item->status !== $filters['status']) {
                return false;
            }

            if (isset($filters['start_datetime'])) {
                if ($item->end_datetime->lt($filters['start_datetime'])) {
                    return false;
                }
            }

            if (isset($filters['end_datetime'])) {
                if ($item->start_datetime->gt($filters['end_datetime'])) {
                    return false;
                }
            }

            return true;
        })->values();
    }
}
