<?php

namespace Modules\Operations\AssetManagement\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\AssetManagement\Services\AssetMovementService;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\DTOs\AssetMovementDTO;

class AssetMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_record_movement()
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $user = \Modules\Foundation\User\Models\User::first();
        
        $category = \Modules\Operations\AssetManagement\Models\AssetCategory::factory()->create();
        $type = \Modules\Operations\AssetManagement\Models\AssetType::factory()->create(['asset_category_id' => $category->id]);

        $asset = Asset::create([
            'property_id' => '01H2',
            'asset_category_id' => $category->id,
            'asset_type_id' => $type->id,
            'name' => 'Chiller',
            'status' => 'Active',
            'condition' => 'Good',
            'criticality' => 'High',
            'location_id' => 'LOC_A'
        ]);

        $service = new AssetMovementService();
        $dto = new AssetMovementDTO(
            property_id: '01H2',
            asset_id: $asset->id,
            movement_type: 'Transfer',
            movement_date: '2026-06-11',
            to_location_id: 'LOC_B',
            user_id: $user->id,
            reason: 'Maintenance move'
        );

        $movement = $service->recordMovement($dto);

        $this->assertNotNull($movement->id);
        $this->assertEquals('LOC_B', $movement->to_location_id);
        $this->assertEquals('LOC_B', $asset->fresh()->location_id);
    }
}
