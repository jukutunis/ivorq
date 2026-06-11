<?php

namespace Modules\Operations\Inventory\Enums;

enum InventoryTypeEnum: string
{
    case CONSUMABLE = 'consumable';
    case SPARE_PART = 'spare_part';
    case TOOL = 'tool';
    case CHEMICAL = 'chemical';
    case AMENITY = 'amenity';
    case LINEN = 'linen';
    case UNIFORM = 'uniform';
    case OFFICE_SUPPLY = 'office_supply';
    case KITCHEN_SUPPLY = 'kitchen_supply';
    case POOL_CHEMICAL = 'pool_chemical';
    case ENGINEERING_MATERIAL = 'engineering_material';
}
