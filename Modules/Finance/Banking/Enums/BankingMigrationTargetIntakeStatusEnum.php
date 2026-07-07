<?php

namespace Modules\Finance\Banking\Enums;

enum BankingMigrationTargetIntakeStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case PROPOSED = 'PROPOSED';
    case REVIEW_ACCEPTED = 'REVIEW_ACCEPTED';
    case REVIEW_REJECTED = 'REVIEW_REJECTED';
    case ARCHIVED = 'ARCHIVED';
}
