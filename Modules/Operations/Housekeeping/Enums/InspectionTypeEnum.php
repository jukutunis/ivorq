<?php

namespace Modules\Operations\Housekeeping\Enums;

enum InspectionTypeEnum: string
{
    case Routine      = 'routine';
    case PostCleaning = 'post_cleaning';
    case Checkout     = 'checkout';
    case Checkin      = 'checkin';
    case SpotCheck    = 'spot_check';

    public function label(): string
    {
        return match($this) {
            self::Routine      => 'Routine',
            self::PostCleaning => 'Post Cleaning',
            self::Checkout     => 'Checkout',
            self::Checkin      => 'Check-in',
            self::SpotCheck    => 'Spot Check',
        };
    }
}
