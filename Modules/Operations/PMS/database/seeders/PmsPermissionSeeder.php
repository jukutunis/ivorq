<?php

namespace Modules\Operations\PMS\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Models\Permission;

class PmsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Guest
            'pms.guest.view',
            'pms.guest.create',
            'pms.guest.edit',
            'pms.guest.delete',

            // Reservation
            'pms.reservation.view',
            'pms.reservation.create',
            'pms.reservation.edit',
            'pms.reservation.delete',
            'pms.reservation.checkin',
            'pms.reservation.checkout',

            // Room Block
            'pms.room-block.view',
            'pms.room-block.create',
            'pms.room-block.edit',
            'pms.room-block.delete',

            // Folio
            'pms.folio.view',
            'pms.folio.manage',

            // Rate Plan
            'pms.rate-plan.view',
            'pms.rate-plan.manage',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
