<?php

namespace Modules\Operations\Zoning\Enums;

enum ZoneTypeEnum: string
{
    case GuestAccommodation = 'guest_accommodation';
    case PublicArea         = 'public_area';
    case FoodBeverage       = 'food_beverage';
    case Recreation         = 'recreation';
    case BackOfHouse        = 'back_of_house';
    case Custom             = 'custom';

    public function label(): string
    {
        return match($this) {
            self::GuestAccommodation => 'Guest Accommodation',
            self::PublicArea         => 'Public Area',
            self::FoodBeverage       => 'Food & Beverage',
            self::Recreation         => 'Recreation',
            self::BackOfHouse        => 'Back of House',
            self::Custom             => 'Custom',
        };
    }
}
