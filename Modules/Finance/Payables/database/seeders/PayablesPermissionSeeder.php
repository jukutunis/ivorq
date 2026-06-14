<?php

namespace Modules\Finance\Payables\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Models\Permission;

class PayablesPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'payables.vendor-invoice.view',
            'payables.vendor-invoice.create',
            'payables.vendor-invoice.edit',
            'payables.vendor-invoice.cancel',
            'payables.match.view',
            'payables.match.create',
            'payables.ap.view',
            'payables.ap.create',
            'payables.payment.view',
            'payables.payment.create',
            'payables.payment.post',
            'payables.payment.cancel',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }
}
