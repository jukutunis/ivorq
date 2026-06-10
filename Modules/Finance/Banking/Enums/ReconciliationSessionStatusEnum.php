<?php

namespace Modules\Finance\Banking\Enums;

enum ReconciliationSessionStatusEnum: string
{
    case Open = 'Open';
    case InProgress = 'InProgress';
    case Review = 'Review';
    case Completed = 'Completed';
    case Cancelled = 'Cancelled';
}
