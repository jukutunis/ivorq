<?php

namespace Modules\OperationsCalendar\Services;

use Illuminate\Support\Collection;
use Modules\SalesAndEventManagement\Models\EventFunction;
use Modules\FunctionSpace\Models\FunctionSpaceBooking;
use Modules\FunctionSpace\Models\VenueMaintenanceBlock;
use Modules\OperationsCalendar\DTOs\CalendarItemDTO;
use Modules\OperationsCalendar\Enums\CalendarItemType;
use Modules\OperationsCalendar\Enums\CalendarSeverity;

class OperationsCalendarService
{
    public function getCalendarItems(string $propertyId, array $filters = []): Collection
    {
        $items = collect();

        // 1. Fetch Event Functions
        $functions = EventFunction::with(['event.opportunity'])
            ->whereHas('event.opportunity', function ($q) use ($propertyId) {
                $q->where('property_id', $propertyId);
            })
            ->get();

        foreach ($functions as $fn) {
            $items->push(new CalendarItemDTO(
                id: 'evt_fn_' . $fn->id,
                source_domain: 'SalesAndEventManagement',
                source_type: CalendarItemType::EventFunction,
                source_id: $fn->id,
                property_id: $propertyId,
                title: $fn->function_name,
                start_datetime: $fn->start_datetime,
                end_datetime: $fn->end_datetime,
                status: $fn->status->value,
                severity: CalendarSeverity::Info,
                venue_id: null, // EventFunction itself isn't intrinsically tied to a venue here, FunctionSpaceBooking is.
                metadata: [
                    'event_id' => $fn->event_id,
                    'event_name' => $fn->event->event_name,
                ]
            ));
        }

        // 2. Fetch Function Space Bookings
        $bookings = FunctionSpaceBooking::with(['venue', 'eventFunction'])
            ->whereHas('venue', function ($q) use ($propertyId) {
                $q->where('property_id', $propertyId);
            })
            ->get();

        foreach ($bookings as $booking) {
            $items->push(new CalendarItemDTO(
                id: 'fs_booking_' . $booking->id,
                source_domain: 'FunctionSpace',
                source_type: CalendarItemType::FunctionSpaceBooking,
                source_id: $booking->id,
                property_id: $propertyId,
                title: $booking->eventFunction ? $booking->eventFunction->function_name . ' (Booking)' : 'Booking',
                start_datetime: $booking->start_datetime,
                end_datetime: $booking->end_datetime,
                status: $booking->status->value,
                severity: CalendarSeverity::Notice,
                venue_id: $booking->venue_id,
                metadata: [
                    'event_function_id' => $booking->event_function_id,
                ]
            ));
        }

        // 3. Fetch Venue Maintenance Blocks
        $maintenanceBlocks = VenueMaintenanceBlock::with('venue')
            ->whereHas('venue', function ($q) use ($propertyId) {
                $q->where('property_id', $propertyId);
            })
            ->get();

        foreach ($maintenanceBlocks as $block) {
            $items->push(new CalendarItemDTO(
                id: 'maint_' . $block->id,
                source_domain: 'FunctionSpace',
                source_type: CalendarItemType::VenueMaintenanceBlock,
                source_id: $block->id,
                property_id: $propertyId,
                title: $block->reason ?? 'Maintenance',
                start_datetime: $block->start_datetime,
                end_datetime: $block->end_datetime,
                status: $block->maintenance_type->value,
                severity: CalendarSeverity::Warning,
                venue_id: $block->venue_id,
                metadata: [
                    'maintenance_type' => $block->maintenance_type->value,
                ]
            ));
        }

        // Apply filters
        $filterEngine = new \Modules\OperationsCalendar\Filters\CalendarFilterEngine();
        
        return $filterEngine->apply($items, $filters);
    }
}
