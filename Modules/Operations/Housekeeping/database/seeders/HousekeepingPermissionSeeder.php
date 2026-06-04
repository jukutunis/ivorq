<?php

namespace Modules\Operations\Housekeeping\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class HousekeepingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'housekeeping.view',
            'housekeeping.create',
            'housekeeping.edit',
            'housekeeping.delete',
            'housekeeping.assign',
            'housekeeping.inspect',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
