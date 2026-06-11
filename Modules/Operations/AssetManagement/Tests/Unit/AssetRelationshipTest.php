<?php

namespace Modules\Operations\AssetManagement\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\AssetManagement\Services\AssetRelationshipService;

class AssetRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_service_exists()
    {
        $service = new AssetRelationshipService();
        $this->assertNotNull($service);
    }
}
