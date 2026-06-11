<?php

namespace Modules\Operations\Maintenance\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Maintenance\Services\MaintenanceScheduleGeneratorService;
use Modules\Operations\Maintenance\Services\MaintenanceExecutionService;
use Modules\Operations\Maintenance\Models\MaintenancePlan;
use Modules\Operations\Maintenance\Models\MaintenanceExecution;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\Models\AssetCategory;
use Modules\Operations\AssetManagement\Models\AssetType;
use Carbon\Carbon;

class MaintenanceScheduleGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_generate_schedule_for_rolling_window()
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
            'frequency' => 'Monthly',
            'next_due_date' => Carbon::now()->addDays(5)->toDateString()
        ]);

        $executionService = new MaintenanceExecutionService();
        $service = new MaintenanceScheduleGeneratorService($executionService);

        $service->generateForRollingWindow(30);

        $this->assertDatabaseHas('maintenance_executions', [
            'maintenance_plan_id' => $plan->id,
            'status' => 'Pending',
        ]);

        $plan->refresh();
        $this->assertTrue(Carbon::parse($plan->next_due_date)->gt(Carbon::now()->addDays(30)));
    }
}
