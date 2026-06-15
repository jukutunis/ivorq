<?php

namespace Modules\PlanningAndBudgeting\Enums;

enum ForecastTypeEnum: string
{
    case Rolling = 'ROLLING';
    case Reforecast = 'REFORECAST';
}
