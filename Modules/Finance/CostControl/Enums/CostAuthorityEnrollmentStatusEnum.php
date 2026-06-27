<?php

namespace Modules\Finance\CostControl\Enums;

enum CostAuthorityEnrollmentStatusEnum: string
{
    case Draft      = 'draft';
    case Approved   = 'approved';
    case Enrolled   = 'enrolled';
    case Rejected   = 'rejected';
    case Superseded = 'superseded';
}
