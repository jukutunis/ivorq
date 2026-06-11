<?php

namespace Modules\Operations\AssetManagement\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\AssetManagement\Services\AssetMovementService;

class AssetMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_movement_service_exists()
    {
        $service = new AssetMovementService();
        $this->assertNotNull($service);
    }
}
