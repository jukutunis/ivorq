<?php

namespace Modules\Operations\Maintenance\Tests\Feature;

use Tests\TestCase;
use Modules\Foundation\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MaintenanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_maintenance_plans()
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::first();
        $user->givePermissionTo('maintenance.view');

        $response = $this->actingAs($user)->getJson('/api/v1/maintenance/plans');
        
        $response->assertStatus(200);
    }

    public function test_can_list_maintenance_executions()
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::first();
        $user->givePermissionTo('maintenance.view');

        $response = $this->actingAs($user)->getJson('/api/v1/maintenance/executions');
        
        $response->assertStatus(200);
    }
}
