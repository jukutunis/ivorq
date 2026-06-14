<?php

namespace Modules\Operations\Inventory\Enums;

enum CountScopeEnum: string
{
    case PROPERTY = 'property';
    case ZONE = 'zone';
    case LOCATION = 'location';
    case ITEM_GROUP = 'item_group';

    public function label(): string
    {
        return match ($this) {
            self::PROPERTY => 'Property',
            self::ZONE => 'Zone',
            self::LOCATION => 'Location',
            self::ITEM_GROUP => 'Item Group',
        };
    }
}
