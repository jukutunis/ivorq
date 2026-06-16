<?php

namespace Modules\SalesAndEventManagement\Enums;

enum OperationalPackageTypeEnum: string
{
    case Wedding = 'WEDDING';
    case HalfDayMeeting = 'HALF_DAY_MEETING';
    case FullDayMeeting = 'FULL_DAY_MEETING';
    case Conference = 'CONFERENCE';
    case CorporateEvent = 'CORPORATE_EVENT';
    case Exhibition = 'EXHIBITION';
    case Banquet = 'BANQUET';
}
