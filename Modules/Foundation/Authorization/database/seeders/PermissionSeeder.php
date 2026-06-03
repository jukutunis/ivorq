<?php

namespace Modules\Foundation\Authorization\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Property
            'property.view', 'property.create', 'property.edit', 'property.delete',

            // Department
            'department.view', 'department.create', 'department.edit', 'department.delete',

            // User
            'user.view', 'user.create', 'user.edit', 'user.delete',

            // Role
            'role.view', 'role.create', 'role.edit', 'role.delete',

            // Audit
            'audit.view',

            // Activity
            'activity.view',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
