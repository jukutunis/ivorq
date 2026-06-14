<?php

namespace Modules\Finance\GeneralLedger\Enums;

enum JournalCandidateStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case PENDING_REVIEW = 'PENDING_REVIEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case POSTED = 'POSTED';
    case POSTING_FAILED = 'POSTING_FAILED';
    case CONFIGURATION_ERROR = 'CONFIGURATION_ERROR';
}
