<?php

namespace Modules\Operations\Maintenance\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Maintenance\Services\MaintenanceMeterReadingService;
use Modules\Operations\Maintenance\DTOs\MaintenanceMeterReadingDTO;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\Models\AssetCategory;
use Modules\Operations\AssetManagement\Models\AssetType;

class MaintenanceMeterReadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_log_meter_reading()
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

        $service = new MaintenanceMeterReadingService();
        $dto = new MaintenanceMeterReadingDTO(
            property_id: '01H2',
            asset_id: $asset->id,
            meter_type: 'Electricity',
            reading_value: 1500.5,
            reading_date: '2026-06-11'
        );

        $reading = $service->logReading($dto);

        $this->assertNotNull($reading->id);
        $this->assertEquals(1500.5, $reading->reading_value);
    }
}
