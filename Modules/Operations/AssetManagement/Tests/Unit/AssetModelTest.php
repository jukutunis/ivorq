<?php

namespace Modules\Operations\AssetManagement\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\Models\AssetCategory;
use Modules\Operations\AssetManagement\Models\AssetType;
use Modules\Operations\AssetManagement\Enums\AssetStatusEnum;
use Modules\Operations\AssetManagement\Enums\AssetConditionEnum;
use Modules\Operations\AssetManagement\Enums\AssetCriticalityEnum;

class AssetModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_asset()
    {
        $category = AssetCategory::create(['property_id' => '01H2', 'name' => 'HVAC']);
        $type = AssetType::create(['property_id' => '01H2', 'asset_category_id' => $category->id, 'name' => 'Chiller']);

        $asset = Asset::create([
            'property_id' => '01H2',
            'asset_category_id' => $category->id,
            'asset_type_id' => $type->id,
            'name' => 'Main Chiller',
            'status' => AssetStatusEnum::PLANNED->value,
            'condition' => AssetConditionEnum::EXCELLENT->value,
            'criticality' => AssetCriticalityEnum::HIGH->value,
        ]);

        $this->assertNotNull($asset->id);
        $this->assertEquals('Main Chiller', $asset->name);
    }
}
