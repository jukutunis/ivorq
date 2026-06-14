<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Database\Seeders\PermissionSeeder;
use Modules\Foundation\Authorization\Database\Seeders\RoleSeeder;
use Modules\Foundation\Department\Database\Seeders\DepartmentSeeder;
use Modules\Foundation\Property\Database\Seeders\PropertySeeder;
use Modules\Foundation\User\Database\Seeders\SuperAdminSeeder;
use Modules\Operations\Engineering\Database\Seeders\EngineeringChecklistSeeder;
use Modules\Operations\Engineering\Database\Seeders\EngineeringPermissionSeeder;
use Modules\Operations\Engineering\Database\Seeders\WorkOrderSeeder;
use Modules\Operations\Housekeeping\Database\Seeders\CleaningChecklistSeeder;
use Modules\Operations\Housekeeping\Database\Seeders\HousekeepingPermissionSeeder;
use Modules\Operations\Housekeeping\Database\Seeders\RoomSeeder;
use Modules\Operations\Inventory\Database\Seeders\InventoryAdjustmentSeeder;
use Modules\Operations\Inventory\Database\Seeders\InventoryCategorySeeder;
use Modules\Operations\Inventory\Database\Seeders\InventoryIssueSeeder;
use Modules\Operations\Inventory\Database\Seeders\InventoryItemSeeder;
use Modules\Operations\Inventory\Database\Seeders\InventoryLocationSeeder;
use Modules\Operations\Inventory\Database\Seeders\InventoryOpeningBalanceSeeder;
use Modules\Operations\Inventory\Database\Seeders\InventoryPermissionSeeder;
use Modules\Operations\Inventory\Database\Seeders\InventoryReceiptSeeder;
use Modules\Operations\Inventory\Database\Seeders\InventoryTransferSeeder;
use Modules\Operations\Inventory\Database\Seeders\InventoryUnitSeeder;
use Modules\Operations\PMS\Database\Seeders\GuestSeeder;
use Modules\Operations\PMS\Database\Seeders\PmsPermissionSeeder;
use Modules\Operations\PMS\Database\Seeders\RatePlanSeeder;
use Modules\Operations\PMS\Database\Seeders\ReservationSeeder;
use Modules\Operations\PMS\Database\Seeders\RoomBlockSeeder;
use Modules\Operations\Zoning\Database\Seeders\ZonePermissionSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Permissions — all permission seeders must run before RoleSeeder
            PermissionSeeder::class,
            ZonePermissionSeeder::class,
            HousekeepingPermissionSeeder::class,
            EngineeringPermissionSeeder::class,
            PmsPermissionSeeder::class,
            InventoryPermissionSeeder::class,
            \Modules\Operations\Purchasing\Database\Seeders\PurchasingPermissionSeeder::class,
            \Modules\Finance\Payables\Database\Seeders\PayablesPermissionSeeder::class,
            \Modules\Operations\AssetManagement\Database\Seeders\AssetPermissionSeeder::class,
            \Modules\Operations\Maintenance\Database\Seeders\MaintenancePermissionSeeder::class,
            \Modules\Operations\WorkOrder\Database\Seeders\WorkOrderPermissionSeeder::class,

            // Roles — must run after all permission seeders (syncPermissions uses Permission::all())
            RoleSeeder::class,

            // Business data
            FoundationSeeder::class,
            \Modules\Foundation\Department\Database\Seeders\PositionSeeder::class,
            DepartmentSeeder::class,
            \Modules\Foundation\Department\Database\Seeders\JobTitleSeeder::class,
            \Modules\Foundation\Department\Database\Seeders\ShiftSeeder::class,

            // Task sample data
            \Modules\Foundation\Task\Database\Seeders\TaskSeeder::class,

            // Housekeeping sample data
            RoomSeeder::class,
            CleaningChecklistSeeder::class,

            // Engineering sample data — must run after RoomSeeder (WorkOrderSeeder uses rooms)
            EngineeringChecklistSeeder::class,
            WorkOrderSeeder::class,

            // PMS sample data — must run after RoomSeeder, GuestSeeder, RatePlanSeeder
            GuestSeeder::class,
            RatePlanSeeder::class,
            ReservationSeeder::class,
            RoomBlockSeeder::class,

            // Inventory master data — must run after PropertySeeder
            InventoryCategorySeeder::class,
            InventoryUnitSeeder::class,
            InventoryLocationSeeder::class,
            InventoryItemSeeder::class,

            // Inventory opening balances — must run after master data seeders
            InventoryOpeningBalanceSeeder::class,

            // Inventory transactions — must run after opening balances
            InventoryReceiptSeeder::class,
            InventoryIssueSeeder::class,
            InventoryTransferSeeder::class,
            InventoryAdjustmentSeeder::class,
            
            // Approval sample data
            \Modules\Foundation\Approval\Database\Seeders\ApprovalSeeder::class,
            
            // Receiving sample data
            \Modules\Operations\Receiving\Database\Seeders\DemoReceivingSeeder::class,
        ]);
    }
}
