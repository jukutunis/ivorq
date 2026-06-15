<?php

namespace Modules\SalesAndEventManagement\Enums;

enum AccountTypeEnum: string
{
    case Corporate = 'CORPORATE';
    case TravelAgent = 'TRAVEL_AGENT';
    case WeddingPlanner = 'WEDDING_PLANNER';
    case Government = 'GOVERNMENT';
    case Association = 'ASSOCIATION';
    case EventOrganizer = 'EVENT_ORGANIZER';
}
