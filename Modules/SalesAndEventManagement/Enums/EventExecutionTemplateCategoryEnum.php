<?php

namespace Modules\SalesAndEventManagement\Enums;

enum EventExecutionTemplateCategoryEnum: string
{
    case Wedding = 'WEDDING';
    case Conference = 'CONFERENCE';
    case Meeting = 'MEETING';
    case CorporateEvent = 'CORPORATE_EVENT';
    case Exhibition = 'EXHIBITION';
    case Banquet = 'BANQUET';
}
