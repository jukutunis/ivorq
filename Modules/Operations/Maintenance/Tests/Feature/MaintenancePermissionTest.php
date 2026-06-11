<?php

namespace Modules\Operations\Maintenance\Tests\Feature;

use Tests\TestCase;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Maintenance\Database\Seeders\MaintenancePermissionSeeder;

class MaintenancePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_are_seeded()
    {
        $this->seed(MaintenancePermissionSeeder::class);
        $this->assertDatabaseHas('permissions', ['name' => 'maintenance.view']);
        $this->assertDatabaseHas('permissions', ['name' => 'maintenance.execute']);
    }

    public function test_user_can_be_assigned_maintenance_permissions()
    {
        $this->seed(MaintenancePermissionSeeder::class);
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        
        $user = User::first();
        $user->givePermissionTo('maintenance.execute');

        $this->assertTrue($user->hasPermissionTo('maintenance.execute'));
    }
}
