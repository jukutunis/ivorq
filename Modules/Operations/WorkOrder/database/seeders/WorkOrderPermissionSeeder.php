<?php

namespace Modules\Operations\WorkOrder\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Models\Permission;

class WorkOrderPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'workorder.view',
            'workorder.create',
            'workorder.update',
            'workorder.assign',
            'workorder.approve',
            'workorder.close',
            'workorder.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }
}
