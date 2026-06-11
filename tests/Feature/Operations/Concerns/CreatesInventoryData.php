<?php

namespace Tests\Feature\Operations\Concerns;

use Modules\Operations\Inventory\Database\Seeders\InventoryPermissionSeeder;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\AdjustmentTypeEnum;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\LocationTypeEnum;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait CreatesInventoryData
{
    use CreatesOperationsData;

    /**
     * Seed Inventory permissions and grant them to property-admin + super-admin roles.
     * Must be called after createPropertyAdmin() / seedPermissionsAndRoles().
     */
    protected function seedInventoryPermissions(): void
    {
        $this->seed(InventoryPermissionSeeder::class);

        foreach (['property-admin', 'super-admin'] as $roleName) {
            Role::where('name', $roleName)->first()
                ?->syncPermissions(Permission::all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function makeInventoryCategory(Property $property, array $overrides = []): InventoryCategory
    {
        static $seq = 0;
        $seq++;

        return InventoryCategory::create(array_merge([
            'property_id'   => $property->id,
                        'name'          => "Policy Category {$seq}",
                    ], $overrides));
    }

    protected function makeInventoryUnit(Property $property, array $overrides = []): InventoryUnit
    {
        static $seq = 0;
        $seq++;

        return InventoryUnit::create(array_merge([
            'property_id'  => $property->id,
                                    'code' => "POL-UNT-{$seq}",
            'name' => "Policy Unit {$seq}",
                    ], $overrides));
    }

    protected function makeInventoryLocation(Property $property, array $overrides = []): InventoryLocation
    {
        static $seq = 0;
        $seq++;

        return InventoryLocation::create(array_merge([
            'property_id'   => $property->id,
                        'name'          => "Policy Location {$seq}",
            'type' => LocationTypeEnum::MainStore->value,
                    ], $overrides));
    }

    protected function makeInventoryItem(
        Property          $property,
        InventoryCategory $category,
        InventoryUnit     $unit,
        array             $overrides = []
    ): InventoryItem {
        static $seq = 0;
        $seq++;

        return InventoryItem::create(array_merge([
            'property_id'   => $property->id,
            'sku'     => "POL-ITM-{$seq}",
            'name'          => "Policy Item {$seq}",
            'inventory_type'  => 'stock',
            'criticality'     => 'low',
            'category_id'   => $category->id,
            'unit_id'       => $unit->id,
                        'weighted_average_cost'  => '0.0000',
                    ], $overrides));
    }

    protected function makeInventoryReceipt(Property $property, array $overrides = []): InventoryReceipt
    {
        static $seq = 0;
        $seq++;

        return InventoryReceipt::create(array_merge([
            'property_id'    => $property->id,
            'receipt_number' => "POL-RCT-{$seq}",
            'status'         => ReceiptStatusEnum::Draft->value,
        ], $overrides));
    }

    protected function makeInventoryIssue(Property $property, array $overrides = []): InventoryIssue
    {
        static $seq = 0;
        $seq++;

        return InventoryIssue::create(array_merge([
            'property_id'  => $property->id,
            'issue_number' => "POL-ISS-{$seq}",
            'status'       => IssueStatusEnum::Draft->value,
        ], $overrides));
    }

    protected function makeInventoryTransfer(
        Property          $property,
        InventoryLocation $from,
        InventoryLocation $to,
        User              $requestedBy,
        array             $overrides = []
    ): InventoryTransfer {
        static $seq = 0;
        $seq++;

        return InventoryTransfer::create(array_merge([
            'property_id'      => $property->id,
            'transfer_number'  => "POL-TRN-{$seq}",
            'from_location_id' => $from->id,
            'to_location_id'   => $to->id,
            'status'           => TransferStatusEnum::Submitted->value,
            'requested_by'     => $requestedBy->id,
        ], $overrides));
    }

    protected function makeInventoryAdjustment(
        Property          $property,
        InventoryLocation $location,
        array             $overrides = []
    ): InventoryAdjustment {
        static $seq = 0;
        $seq++;

        return InventoryAdjustment::create(array_merge([
            'property_id'       => $property->id,
            'adjustment_number' => "POL-ADJ-{$seq}",
            'location_id'       => $location->id,
            'adjustment_type'   => AdjustmentTypeEnum::StockTake->value,
            'status'            => AdjustmentStatusEnum::Submitted->value,
            'reason'            => 'Policy test adjustment',
        ], $overrides));
    }
}
