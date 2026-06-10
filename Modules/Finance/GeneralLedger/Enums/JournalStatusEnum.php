<?php

namespace Modules\Finance\GeneralLedger\Enums;

enum JournalStatusEnum: string
{
    case Draft = 'Draft';
    case Posted = 'Posted';
    case Voided = 'Voided';
}
