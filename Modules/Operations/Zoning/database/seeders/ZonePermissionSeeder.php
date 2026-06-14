<?php

namespace Modules\Operations\Zoning\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Models\Permission;

class ZonePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'zone.view',
            'zone.create',
            'zone.edit',
            'zone.delete',
            'zone.assign',
            'zone.archive',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
