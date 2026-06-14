<?php

namespace Modules\Operations\Receiving\Enums;

enum InspectionResultEnum: string
{
    case Pass = 'PASS';
    case Fail = 'FAIL';
    case Conditional = 'CONDITIONAL';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Pass',
            self::Fail => 'Fail',
            self::Conditional => 'Conditional',
        };
    }
}
