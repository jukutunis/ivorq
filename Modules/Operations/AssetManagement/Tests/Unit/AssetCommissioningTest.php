<?php

namespace Modules\Operations\AssetManagement\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\AssetManagement\Services\AssetCommissioningService;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\DTOs\AssetCommissioningDTO;

class AssetCommissioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_execute_commissioning()
    {
        $category = \Modules\Operations\AssetManagement\Models\AssetCategory::factory()->create();
        $type = \Modules\Operations\AssetManagement\Models\AssetType::factory()->create(['asset_category_id' => $category->id]);

        $asset = Asset::create([
            'property_id' => '01H2',
            'asset_category_id' => $category->id,
            'asset_type_id' => $type->id,
            'name' => 'Chiller',
            'status' => 'Received',
            'condition' => 'Excellent',
            'criticality' => 'High',
        ]);

        $service = new AssetCommissioningService();
        $dto = new AssetCommissioningDTO(
            property_id: '01H2',
            asset_id: $asset->id,
            status: 'Approved',
            acceptance_test_date: '2026-06-11'
        );

        $commissioning = $service->executeCommissioning($dto);

        $this->assertNotNull($commissioning->id);
        $this->assertEquals('Approved', $commissioning->status);
        $this->assertEquals('Commissioned', $asset->fresh()->status);
    }
}
