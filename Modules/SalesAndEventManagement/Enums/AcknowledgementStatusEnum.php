<?php

namespace Modules\SalesAndEventManagement\Enums;

enum AcknowledgementStatusEnum: string
{
    case PENDING = 'PENDING';
    case VIEWED = 'VIEWED';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case REJECTED = 'REJECTED';
    case ESCALATED = 'ESCALATED';

    // Supersede cascade statuses — Sprint 14.8.5.1 §4
    case SUPERSEDED_NO_ACTION = 'SUPERSEDED_NO_ACTION';
    case SUPERSEDED_ESCALATION_CLOSED = 'SUPERSEDED_ESCALATION_CLOSED';
}
