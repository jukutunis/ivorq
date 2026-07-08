<?php

namespace Modules\Operations\FrontDesk\Enums;

enum FrontDeskRoomAssignmentKindEnum: string
{
    case InitialAssignment = 'INITIAL_ASSIGNMENT';
    case RoomMove = 'ROOM_MOVE';
}
