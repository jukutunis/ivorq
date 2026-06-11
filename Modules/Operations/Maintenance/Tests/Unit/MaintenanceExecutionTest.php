<?php

namespace Modules\Operations\Maintenance\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Maintenance\Services\MaintenanceExecutionService;
use Modules\Operations\Maintenance\DTOs\MaintenanceExecutionDTO;
use Modules\Operations\Maintenance\Models\MaintenancePlan;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\Models\AssetCategory;
use Modules\Operations\AssetManagement\Models\AssetType;

class MaintenanceExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_generate_and_complete_execution()
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

        $plan = MaintenancePlan::create([
            'property_id' => '01H2',
            'asset_id' => $asset->id,
            'title' => 'Monthly HVAC Service',
            'maintenance_type' => 'Time Based',
            'status' => 'Active',
            'frequency' => 'Monthly'
        ]);

        $service = new MaintenanceExecutionService();
        $dto = new MaintenanceExecutionDTO(
            property_id: '01H2',
            maintenance_plan_id: $plan->id,
            asset_id: $asset->id,
            status: 'Pending',
            scheduled_date: '2026-06-15'
        );

        $execution = $service->generateExecution($dto);

        $this->assertNotNull($execution->id);
        $this->assertEquals('Pending', $execution->status);

        $completed = $service->completeExecution($execution, [], 'user_123', 'Completed successfully');

        $this->assertEquals('Completed', $completed->status);
        $this->assertEquals('user_123', $completed->executed_by);
    }
}
