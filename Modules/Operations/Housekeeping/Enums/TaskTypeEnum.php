<?php

namespace Modules\Operations\Housekeeping\Enums;

enum TaskTypeEnum: string
{
    case CheckoutCleaning  = 'checkout_cleaning';
    case StayoverCleaning  = 'stayover_cleaning';
    case Turndown          = 'turndown';
    case DeepCleaning      = 'deep_cleaning';
    case PublicArea        = 'public_area';
    case SpotCheck         = 'spot_check';
    case Custom            = 'custom';

    public function label(): string
    {
        return match($this) {
            self::CheckoutCleaning  => 'Checkout Cleaning',
            self::StayoverCleaning  => 'Stayover Cleaning',
            self::Turndown          => 'Turndown',
            self::DeepCleaning      => 'Deep Cleaning',
            self::PublicArea        => 'Public Area',
            self::SpotCheck         => 'Spot Check',
            self::Custom            => 'Custom',
        };
    }
}
