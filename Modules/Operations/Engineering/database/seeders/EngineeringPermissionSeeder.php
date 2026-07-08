<?php

namespace Modules\Operations\Engineering\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Models\Permission;

class EngineeringPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Work Orders
            'engineering.work-order.view',
            'engineering.work-order.create',
            'engineering.work-order.edit',
            'engineering.work-order.delete',
            'engineering.work-order.assign',
            'engineering.work-order.approve',

            // Preventive Maintenance
            'engineering.pm.view',
            'engineering.pm.create',
            'engineering.pm.edit',
            'engineering.pm.delete',

            // Asset Requests
            'engineering.asset-request.view',
            'engineering.asset-request.create',
            'engineering.asset-request.edit',
            'engineering.asset-request.approve',

            // Checklists
            'engineering.checklist.view',
            'engineering.checklist.create',
            'engineering.checklist.edit',
            'engineering.checklist.delete',

            // Room Availability
            'engineering.room-availability.view',
            'engineering.room-availability.block',
            'engineering.room-availability.release',
            'frontdesk.engineering-availability.view',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
