<?php

namespace Modules\Finance\Payables\Enums;

enum MatchStatusEnum: string
{
    case Matched = 'Matched';
    case MatchedWithVariance = 'MatchedWithVariance';
    case Exception = 'Exception';
}
