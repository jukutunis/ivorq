<?php

namespace Modules\Operations\AssetManagement\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Models\Permission;

class AssetPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'asset.view',
            'asset.create',
            'asset.update',
            'asset.delete',
            'asset.movement.create',
            'asset.warranty.view',
            'asset.warranty.manage',
            'asset.commissioning.execute',
            'asset.admin',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }
}
