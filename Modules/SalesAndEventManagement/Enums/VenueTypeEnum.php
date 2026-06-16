<?php

namespace Modules\SalesAndEventManagement\Enums;

enum VenueTypeEnum: string
{
    case Ballroom = 'BALLROOM';
    case Boardroom = 'BOARDROOM';
    case Outdoor = 'OUTDOOR';
    case Garden = 'GARDEN';
    case MeetingRoom = 'MEETING_ROOM';
}
