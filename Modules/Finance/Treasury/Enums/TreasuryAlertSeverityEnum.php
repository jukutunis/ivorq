<?php

namespace Modules\Finance\Treasury\Enums;

enum TreasuryAlertSeverityEnum: string
{
    case Info = 'Info';
    case Warning = 'Warning';
    case High = 'High';
    case Critical = 'Critical';
}
