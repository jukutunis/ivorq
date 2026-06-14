<?php

namespace Modules\Operations\AssetManagement\Tests\Feature;

use Tests\TestCase;
use Modules\Operations\AssetManagement\Database\Seeders\AssetPermissionSeeder;
use Modules\Foundation\Authorization\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AssetPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_are_seeded()
    {
        $this->seed(AssetPermissionSeeder::class);

        $this->assertDatabaseHas('permissions', ['name' => 'asset.view']);
        $this->assertDatabaseHas('permissions', ['name' => 'asset.admin']);
    }
}
