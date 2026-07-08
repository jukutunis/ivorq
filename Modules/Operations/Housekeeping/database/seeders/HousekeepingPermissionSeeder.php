<?php

namespace Modules\Operations\Housekeeping\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Models\Permission;

class HousekeepingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Room
            'housekeeping.room.view',
            'housekeeping.room.create',
            'housekeeping.room.edit',
            'housekeeping.room.delete',
            'housekeeping.room.cleanliness',
            'housekeeping.room.occupancy',

            // Task
            'housekeeping.task.view',
            'housekeeping.task.create',
            'housekeeping.task.edit',
            'housekeeping.task.delete',
            'housekeeping.task.assign',
            'housekeeping.task.start',
            'housekeeping.task.complete',
            'housekeeping.task.cancel',

            // Checklist
            'housekeeping.checklist.view',
            'housekeeping.checklist.create',
            'housekeeping.checklist.edit',
            'housekeeping.checklist.delete',

            // Inspection
            'housekeeping.inspection.view',
            'housekeeping.inspection.create',
            'housekeeping.inspection.conduct',
            'housekeeping.inspection.approve',

            // Room Readiness
            'housekeeping.room-readiness.view',
            'housekeeping.room-readiness.clean',
            'housekeeping.room-readiness.submit-inspection',
            'housekeeping.room-readiness.release-ready',
            'frontdesk.housekeeping-readiness.view',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
