<?php

namespace Modules\Operations\Maintenance\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Maintenance\Services\MaintenancePlanService;
use Modules\Operations\Maintenance\DTOs\MaintenancePlanDTO;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\Models\AssetCategory;
use Modules\Operations\AssetManagement\Models\AssetType;

class MaintenancePlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_maintenance_plan()
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $category = AssetCategory::factory()->create();
        $type = AssetType::factory()->create(['asset_category_id' => $category->id]);

        $asset = Asset::create([
            'property_id' => '01H2',
            'asset_category_id' => $category->id,
            'asset_type_id' => $type->id,
            'name' => 'Test Asset',
            'status' => 'Active',
            'condition' => 'Good',
            'criticality' => 'Medium',
        ]);

        $service = new MaintenancePlanService();
        $dto = new MaintenancePlanDTO(
            property_id: '01H2',
            asset_id: $asset->id,
            title: 'Monthly HVAC Service',
            maintenance_type: 'Time Based',
            status: 'Active',
            frequency: 'Monthly'
        );

        $plan = $service->createPlan($dto);

        $this->assertNotNull($plan->id);
        $this->assertEquals('Monthly HVAC Service', $plan->title);
    }
}
