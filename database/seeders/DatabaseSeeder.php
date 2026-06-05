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
use Modules\Operations\Engineering\Database\Seeders\PreventiveMaintenanceSeeder;
use Modules\Operations\Engineering\Database\Seeders\WorkOrderSeeder;
use Modules\Operations\Housekeeping\Database\Seeders\CleaningChecklistSeeder;
use Modules\Operations\Housekeeping\Database\Seeders\HousekeepingPermissionSeeder;
use Modules\Operations\Housekeeping\Database\Seeders\RoomSeeder;
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

            // Roles — must run after all permission seeders (syncPermissions uses Permission::all())
            RoleSeeder::class,

            // Business data
            PropertySeeder::class,
            DepartmentSeeder::class,
            SuperAdminSeeder::class,

            // Housekeeping sample data
            RoomSeeder::class,
            CleaningChecklistSeeder::class,

            // Engineering sample data — must run after RoomSeeder (WorkOrderSeeder uses rooms)
            EngineeringChecklistSeeder::class,
            PreventiveMaintenanceSeeder::class,
            WorkOrderSeeder::class,

            // PMS sample data — must run after RoomSeeder, GuestSeeder, RatePlanSeeder
            GuestSeeder::class,
            RatePlanSeeder::class,
            ReservationSeeder::class,
            RoomBlockSeeder::class,
        ]);
    }
}
