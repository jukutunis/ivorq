<?php

namespace Modules\OperationsCalendar\Enums;

enum CalendarItemType: string
{
    case EventFunction = 'EVENT_FUNCTION';
    case FunctionSpaceBooking = 'FUNCTION_SPACE_BOOKING';
    case VenueMaintenanceBlock = 'VENUE_MAINTENANCE_BLOCK';
    case ResourceAllocation = 'RESOURCE_ALLOCATION';
}
