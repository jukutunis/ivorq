<?php

namespace Modules\Finance\Payables\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PayablesPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'payables.vendor-invoice.view',
            'payables.vendor-invoice.create',
            'payables.vendor-invoice.edit',
            'payables.vendor-invoice.cancel',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }
}
