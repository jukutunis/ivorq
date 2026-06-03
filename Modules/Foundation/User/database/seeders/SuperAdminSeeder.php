<?php

namespace Modules\Foundation\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\User\Models\User;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = User::create([
            'name'              => 'Super Administrator',
            'email'             => 'superadmin@ivorq.com',
            'password'          => 'password',
            'property_id'       => null,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);

        $superAdmin->assignRole('super-admin');
    }
}
