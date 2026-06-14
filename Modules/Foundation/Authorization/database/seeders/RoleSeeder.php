<?php

namespace Modules\Foundation\Authorization\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Super Admin — global, no property scope
        $superAdmin = Role::firstOrCreate([
            'name'       => 'super-admin',
            'guard_name' => 'web',
            'property_id'    => null,
        ]);
        $superAdmin->syncPermissions(Permission::all());

        // Property Admin — gets all permissions within their property
        $propertyAdmin = Role::firstOrCreate([
            'name'       => 'property-admin',
            'guard_name' => 'web',
            'property_id'    => null,
        ]);
        $propertyAdmin->syncPermissions(Permission::all());

        // General Manager — operational management permissions
        $generalManager = Role::firstOrCreate([
            'name'       => 'general-manager',
            'guard_name' => 'web',
            'property_id'    => null,
        ]);
        $generalManager->syncPermissions([
            // Foundation
            'department.view', 'department.create', 'department.edit',
            'user.view', 'user.create', 'user.edit',
            'role.view',
            'audit.view', 'activity.view',
            // Task
            'task.view', 'task.create', 'task.assign', 'task.complete', 'task.cancel', 'task.delete',
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
            // PMS — Guest
            'pms.guest.view', 'pms.guest.create', 'pms.guest.edit',
            // PMS — Reservation
            'pms.reservation.view', 'pms.reservation.create', 'pms.reservation.edit',
            'pms.reservation.delete', 'pms.reservation.checkin', 'pms.reservation.checkout',
            // PMS — Room Block
            'pms.room-block.view', 'pms.room-block.create', 'pms.room-block.edit', 'pms.room-block.delete',
            // PMS — Folio
            'pms.folio.view', 'pms.folio.manage',
            // PMS — Rate Plan (view only for manager)
            'pms.rate-plan.view',
        ]);

        // Staff — basic operational access
        $staff = Role::firstOrCreate([
            'name'       => 'staff',
            'guard_name' => 'web',
            'property_id'    => null,
        ]);
        $staff->syncPermissions([
            'activity.view',
            'task.view',
            'task.complete',
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

        // Department Head — department level management
        $departmentHead = Role::firstOrCreate([
            'name'       => 'department-head',
            'guard_name' => 'web',
            'property_id'    => null,
        ]);
        // Give same permissions as general-manager for demo
        $departmentHead->syncPermissions($generalManager->permissions);

        // Supervisor — supervisory roles
        $supervisor = Role::firstOrCreate([
            'name'       => 'supervisor',
            'guard_name' => 'web',
            'property_id'    => null,
        ]);
        // Give staff permissions plus some extras for demo
        $supervisor->syncPermissions($staff->permissions);
    }
}
