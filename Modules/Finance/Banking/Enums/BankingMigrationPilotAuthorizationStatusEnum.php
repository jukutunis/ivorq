<?php

namespace Modules\Finance\Banking\Enums;

enum BankingMigrationPilotAuthorizationStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case REQUESTED = 'REQUESTED';
    case REVIEW_ACCEPTED = 'REVIEW_ACCEPTED';
    case REVIEW_REJECTED = 'REVIEW_REJECTED';
    case ARCHIVED = 'ARCHIVED';
}
