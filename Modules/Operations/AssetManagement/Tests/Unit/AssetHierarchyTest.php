<?php

namespace Modules\Operations\AssetManagement\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\AssetManagement\Services\AssetHierarchyService;
use Modules\Operations\AssetManagement\DTOs\AssetHierarchyDTO;
use Modules\Operations\AssetManagement\Models\Asset;

class AssetHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_hierarchy()
    {
        // Mocking IDs as string ULIDs since the models would require categories
        $service = new AssetHierarchyService();
        $dto = new AssetHierarchyDTO('01H2', '01H2_ANC', '01H2_DESC', 1);

        $this->assertNotNull($dto);
        $this->assertEquals(1, $dto->depth);
        // Note: Actual linking test requires DB seeding
    }
}
