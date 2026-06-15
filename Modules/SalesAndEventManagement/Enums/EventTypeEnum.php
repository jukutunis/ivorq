<?php

namespace Modules\SalesAndEventManagement\Enums;

enum EventTypeEnum: string
{
    case Wedding = 'WEDDING';
    case Meeting = 'MEETING';
    case Conference = 'CONFERENCE';
    case Banquet = 'BANQUET';
    case CorporateEvent = 'CORPORATE_EVENT';
    case PrivateEvent = 'PRIVATE_EVENT';
    case Exhibition = 'EXHIBITION';
}
