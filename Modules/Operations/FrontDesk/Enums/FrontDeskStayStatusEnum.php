<?php

namespace Modules\Operations\FrontDesk\Enums;

enum FrontDeskStayStatusEnum: string
{
    case ArrivalReady = 'ARRIVAL_READY';
    case RoomAssigned = 'ROOM_ASSIGNED';
    case CheckInConfirmationPending = 'CHECK_IN_CONFIRMATION_PENDING';
    case InHouse = 'IN_HOUSE';
    case CheckedOut = 'CHECKED_OUT';
}
