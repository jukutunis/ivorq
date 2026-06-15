<?php

namespace Modules\PlanningAndBudgeting\Enums;

enum RevenueMetricEnum: string
{
    case Occupancy = 'OCCUPANCY';
    case Adr = 'ADR';
    case RoomNights = 'ROOM_NIGHTS';
    case RevenueTarget = 'REVENUE_TARGET';
}
