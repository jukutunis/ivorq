<?php

namespace Modules\Operations\AssetManagement\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\AssetManagement\Services\AssetCommissioningService;

class AssetCommissioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_commissioning_service_exists()
    {
        $service = new AssetCommissioningService();
        $this->assertNotNull($service);
    }
}
