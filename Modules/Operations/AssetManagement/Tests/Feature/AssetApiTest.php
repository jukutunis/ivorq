<?php

namespace Modules\Operations\AssetManagement\Tests\Feature;

use Tests\TestCase;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\AssetManagement\Database\Seeders\AssetPermissionSeeder;

class AssetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_assets()
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = User::first();
        $user->givePermissionTo('asset.view');

        $response = $this->actingAs($user)->getJson('/api/v1/assets');

        $response->assertStatus(200);
    }
}
