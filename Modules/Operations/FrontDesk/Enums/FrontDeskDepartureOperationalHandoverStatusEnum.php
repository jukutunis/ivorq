<?php

namespace Modules\Operations\FrontDesk\Enums;

enum FrontDeskDepartureOperationalHandoverStatusEnum: string
{
    case OperationalHandoverReady = 'OPERATIONAL_HANDOVER_READY';
    case OperationalHandoverBlocked = 'OPERATIONAL_HANDOVER_BLOCKED';
    case OperationalHandoverReviewed = 'OPERATIONAL_HANDOVER_REVIEWED';
}
