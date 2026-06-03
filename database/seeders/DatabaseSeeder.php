<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Database\Seeders\PermissionSeeder;
use Modules\Foundation\Authorization\Database\Seeders\RoleSeeder;
use Modules\Foundation\Department\Database\Seeders\DepartmentSeeder;
use Modules\Foundation\Property\Database\Seeders\PropertySeeder;
use Modules\Foundation\User\Database\Seeders\SuperAdminSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            PropertySeeder::class,
            DepartmentSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
