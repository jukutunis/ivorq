<?php
$basePath = '/Users/gedeedi/Herd/ivorq/Modules/Operations/Inventory/Enums';
if (!is_dir($basePath)) mkdir($basePath, 0755, true);

$enums = [
    'InventoryTypeEnum.php' => "<?php\n\nnamespace Modules\Operations\Inventory\Enums;\n\nenum InventoryTypeEnum: string\n{\n    case CONSUMABLE = 'consumable';\n    case SPARE_PART = 'spare_part';\n    case TOOL = 'tool';\n    case CHEMICAL = 'chemical';\n    case AMENITY = 'amenity';\n    case LINEN = 'linen';\n    case UNIFORM = 'uniform';\n    case OFFICE_SUPPLY = 'office_supply';\n    case KITCHEN_SUPPLY = 'kitchen_supply';\n    case POOL_CHEMICAL = 'pool_chemical';\n    case ENGINEERING_MATERIAL = 'engineering_material';\n}\n",
    'InventoryCriticalityEnum.php' => "<?php\n\nnamespace Modules\Operations\Inventory\Enums;\n\nenum InventoryCriticalityEnum: string\n{\n    case CRITICAL = 'critical';\n    case HIGH = 'high';\n    case MEDIUM = 'medium';\n    case LOW = 'low';\n}\n",
    'InventoryTransactionTypeEnum.php' => "<?php\n\nnamespace Modules\Operations\Inventory\Enums;\n\nenum InventoryTransactionTypeEnum: string\n{\n    case RECEIVE = 'receive';\n    case ISSUE = 'issue';\n    case TRANSFER = 'transfer';\n    case ADJUSTMENT = 'adjustment';\n    case CONSUMPTION = 'consumption';\n    case RETURN = 'return';\n    case WRITE_OFF = 'write_off';\n    case CYCLE_COUNT = 'cycle_count';\n    case TOOL_CHECKOUT = 'tool_checkout';\n    case TOOL_RETURN = 'tool_return';\n}\n",
    'InventoryCountStatusEnum.php' => "<?php\n\nnamespace Modules\Operations\Inventory\Enums;\n\nenum InventoryCountStatusEnum: string\n{\n    case DRAFT = 'draft';\n    case SYNCED = 'synced';\n    case SUBMITTED = 'submitted';\n    case APPROVED = 'approved';\n    case REJECTED = 'rejected';\n}\n",
    'InventoryReservationStatusEnum.php' => "<?php\n\nnamespace Modules\Operations\Inventory\Enums;\n\nenum InventoryReservationStatusEnum: string\n{\n    case PENDING = 'pending';\n    case RESERVED = 'reserved';\n    case CONSUMED = 'consumed';\n    case CANCELLED = 'cancelled';\n}\n",
    'ToolStatusEnum.php' => "<?php\n\nnamespace Modules\Operations\Inventory\Enums;\n\nenum ToolStatusEnum: string\n{\n    case AVAILABLE = 'available';\n    case CHECKED_OUT = 'checked_out';\n    case IN_CALIBRATION = 'in_calibration';\n    case IN_INSPECTION = 'in_inspection';\n    case DAMAGED = 'damaged';\n    case LOST = 'lost';\n}\n",
    'ABCClassificationEnum.php' => "<?php\n\nnamespace Modules\Operations\Inventory\Enums;\n\nenum ABCClassificationEnum: string\n{\n    case A = 'a';\n    case B = 'b';\n    case C = 'c';\n}\n",
];

foreach ($enums as $filename => $content) {
    file_put_contents("$basePath/$filename", $content);
}
echo "Enums generated.";
