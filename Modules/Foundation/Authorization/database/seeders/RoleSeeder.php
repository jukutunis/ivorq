<?php

namespace Modules\Foundation\Authorization\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Super Admin — global, no property scope
        $superAdmin = Role::firstOrCreate([
            'name'       => 'super-admin',
            'guard_name' => 'web',
            'team_id'    => null,
        ]);
        $superAdmin->syncPermissions(Permission::all());

        // Property Admin — gets all permissions within their property
        $propertyAdmin = Role::firstOrCreate([
            'name'       => 'property-admin',
            'guard_name' => 'web',
            'team_id'    => null,
        ]);
        $propertyAdmin->syncPermissions(Permission::all());

        // Manager — operational management permissions
        $manager = Role::firstOrCreate([
            'name'       => 'manager',
            'guard_name' => 'web',
            'team_id'    => null,
        ]);
        $manager->syncPermissions([
            // Foundation
            'department.view', 'department.create', 'department.edit',
            'user.view', 'user.create', 'user.edit',
            'role.view',
            'audit.view', 'activity.view',
            // Zoning
            'zone.view', 'zone.create', 'zone.edit', 'zone.assign', 'zone.archive',
            // Housekeeping — Room
            'housekeeping.room.view', 'housekeeping.room.create', 'housekeeping.room.edit',
            'housekeeping.room.cleanliness', 'housekeeping.room.occupancy',
            // Housekeeping — Task
            'housekeeping.task.view', 'housekeeping.task.create', 'housekeeping.task.edit',
            'housekeeping.task.assign', 'housekeeping.task.start',
            'housekeeping.task.complete', 'housekeeping.task.cancel',
            // Housekeeping — Checklist
            'housekeeping.checklist.view', 'housekeeping.checklist.create', 'housekeeping.checklist.edit',
            // Housekeeping — Inspection
            'housekeeping.inspection.view', 'housekeeping.inspection.create',
            'housekeeping.inspection.conduct', 'housekeeping.inspection.approve',
            // Engineering — Work Orders
            'engineering.work-order.view', 'engineering.work-order.create', 'engineering.work-order.edit',
            'engineering.work-order.delete', 'engineering.work-order.assign', 'engineering.work-order.approve',
            // Engineering — Preventive Maintenance
            'engineering.pm.view', 'engineering.pm.create', 'engineering.pm.edit', 'engineering.pm.delete',
            // Engineering — Asset Requests
            'engineering.asset-request.view', 'engineering.asset-request.create',
            'engineering.asset-request.edit', 'engineering.asset-request.approve',
            // Engineering — Checklists
            'engineering.checklist.view', 'engineering.checklist.create',
            'engineering.checklist.edit', 'engineering.checklist.delete',
        ]);

        // Staff — basic operational access
        $staff = Role::firstOrCreate([
            'name'       => 'staff',
            'guard_name' => 'web',
            'team_id'    => null,
        ]);
        $staff->syncPermissions([
            'activity.view',
            'zone.view',
            // Housekeeping — Room
            'housekeeping.room.view',
            // Housekeeping — Task
            'housekeeping.task.view',
            'housekeeping.task.start',
            'housekeeping.task.complete',
            // Housekeeping — Checklist
            'housekeeping.checklist.view',
            // Housekeeping — Inspection
            'housekeeping.inspection.view',
            'housekeeping.inspection.conduct',
            // Engineering — no access for staff role
            // (engineering.work-order.view etc. are manager-level and above)
        ]);
    }
}
