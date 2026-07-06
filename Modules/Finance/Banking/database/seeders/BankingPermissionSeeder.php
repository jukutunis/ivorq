<?php

namespace Modules\Finance\Banking\database\seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Models\Permission;

class BankingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'banking.bank-account.view',
            'banking.bank-account.create',
            'banking.bank-account.edit',
            'banking.bank-account.delete',

            'banking.statement.view',
            'banking.statement.create',
            'banking.statement.import',

            'banking.reconciliation.view',
            'banking.reconciliation.create',
            'banking.reconciliation.manage',

            // Banking — Migration Control Plane
            'finance.banking.migration.view',
            'finance.banking.migration.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }
}
