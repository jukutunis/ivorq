<?php

namespace Modules\Finance\Forecasting\Enums;

enum ForecastVersionStatusEnum: string
{
    case Draft = 'Draft';
    case Submitted = 'Submitted';
    case Approved = 'Approved';
    case Rejected = 'Rejected';
    case Locked = 'Locked';
}
