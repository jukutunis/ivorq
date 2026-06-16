<?php

namespace Modules\SalesAndEventManagement\Enums;

enum RevenueClassificationEnum: string
{
    case RoomRental = 'ROOM_RENTAL';
    case FoodAndBeverage = 'FOOD_AND_BEVERAGE';
    case AudioVisual = 'AUDIO_VISUAL';
    case PackageRevenue = 'PACKAGE_REVENUE';
}
