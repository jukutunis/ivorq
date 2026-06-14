<?php

namespace Modules\Operations\Maintenance\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Models\Permission;

class MaintenancePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'maintenance.view',
            'maintenance.create',
            'maintenance.update',
            'maintenance.execute',
            'maintenance.complete',
            'maintenance.cancel',
            'maintenance.admin',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }
}
