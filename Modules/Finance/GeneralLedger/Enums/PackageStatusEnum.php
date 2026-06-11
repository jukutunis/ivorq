<?php

namespace Modules\Finance\GeneralLedger\Enums;

enum PackageStatusEnum: string
{
    case Valid = 'Valid';
    case Warning = 'Warning';
    case Invalid = 'Invalid';
}
