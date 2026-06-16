<?php

namespace Modules\OperationsCalendar\Enums;

enum CalendarSeverity: string
{
    case Info = 'INFO';
    case Notice = 'NOTICE';
    case Warning = 'WARNING';
    case Critical = 'CRITICAL';
}
