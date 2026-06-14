<?php

namespace Modules\Operations\Purchasing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\Authorization\Models\Permission;

class PurchasingRoleSeeder extends Seeder
{
    public function run(): void
    {
        $managerRole = Role::firstOrCreate(['name' => 'Purchasing Manager', 'guard_name' => 'web']);
        $agentRole = Role::firstOrCreate(['name' => 'Purchasing Agent', 'guard_name' => 'web']);

        $permissions = Permission::where('name', 'like', 'purchasing.%')->get();
        
        $managerRole->syncPermissions($permissions);
        
        $agentPermissions = $permissions->filter(function ($p) {
            return !in_array($p->name, ['purchasing.approve']);
        });
        $agentRole->syncPermissions($agentPermissions);
    }
}
