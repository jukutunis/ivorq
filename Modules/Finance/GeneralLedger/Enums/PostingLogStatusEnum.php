<?php

namespace Modules\Finance\GeneralLedger\Enums;

enum PostingLogStatusEnum: string
{
    case Success = 'Success';
    case Failed = 'Failed';
}
