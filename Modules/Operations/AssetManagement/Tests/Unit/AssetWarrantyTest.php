<?php

namespace Modules\Operations\AssetManagement\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\AssetManagement\Models\AssetWarranty;

class AssetWarrantyTest extends TestCase
{
    use RefreshDatabase;

    public function test_warranty_model_exists()
    {
        $warranty = new AssetWarranty();
        $this->assertNotNull($warranty);
    }
}
