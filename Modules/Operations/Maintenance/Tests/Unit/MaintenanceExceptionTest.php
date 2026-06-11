<?php

namespace Modules\Operations\Maintenance\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Maintenance\Services\MaintenanceExceptionService;
use Modules\Operations\Maintenance\DTOs\MaintenanceExceptionDTO;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\Models\AssetCategory;
use Modules\Operations\AssetManagement\Models\AssetType;

class MaintenanceExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_log_exception()
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

        $service = new MaintenanceExceptionService();
        $dto = new MaintenanceExceptionDTO(
            property_id: '01H2',
            asset_id: $asset->id,
            exception_type: 'Checklist Failure',
            status: 'Open',
            description: 'Failed pressure test'
        );

        $exception = $service->logException($dto);

        $this->assertNotNull($exception->id);
        $this->assertEquals('Checklist Failure', $exception->exception_type);
    }
}
