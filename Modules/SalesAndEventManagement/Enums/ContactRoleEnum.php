<?php

namespace Modules\SalesAndEventManagement\Enums;

enum ContactRoleEnum: string
{
    case PrimaryContact = 'PRIMARY_CONTACT';
    case BillingContact = 'BILLING_CONTACT';
    case EventContact = 'EVENT_CONTACT';
    case DecisionMaker = 'DECISION_MAKER';
}
