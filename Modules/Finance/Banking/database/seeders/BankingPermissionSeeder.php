<?php

namespace Modules\Finance\Banking\database\seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class BankingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'banking.bank-account.view',
            'banking.bank-account.create',
            'banking.bank-account.edit',
            'banking.bank-account.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }
}
