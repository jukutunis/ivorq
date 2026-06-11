<?php

namespace Modules\Operations\EngineeringWorkspace\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class WorkspaceApiTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    protected $company;
    protected $property;
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany();
        $this->property = $this->createProperty($this->company);
        $this->admin = $this->createPropertyAdmin($this->property);
        app(CurrentPropertyService::class)->setId($this->property->id);
    }

    public function test_can_get_dashboard()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/operations/workspace/engineering/dashboard');
        $response->assertStatus(200)->assertJsonStructure(['data' => ['open_work_orders', 'pm_compliance', 'critical_incidents']]);
    }

    public function test_can_get_my_tasks()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/operations/workspace/engineering/my-tasks');
        $response->assertStatus(200)->assertJsonStructure(['data' => ['assigned_work_orders', 'assigned_pms']]);
    }

    public function test_can_get_my_areas()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/operations/workspace/engineering/my-areas');
        $response->assertStatus(200)->assertJsonStructure(['data' => ['areas', 'buildings']]);
    }

    public function test_can_get_guest_impact()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/operations/workspace/engineering/guest-impact');
        $response->assertStatus(200)->assertJsonStructure(['data' => ['room_ooo']]);
    }

    public function test_can_get_asset_health()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/operations/workspace/engineering/asset-health');
        $response->assertStatus(200)->assertJsonStructure(['data' => ['high_risk_assets']]);
    }

    public function test_can_get_handover()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/operations/workspace/engineering/handover');
        $response->assertStatus(200)->assertJsonStructure(['data' => ['open_handover', 'pending_acknowledgement']]);
    }

    public function test_can_get_approvals()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/operations/workspace/engineering/approvals');
        $response->assertStatus(200)->assertJsonStructure(['data' => ['pending_approvals']]);
    }
}
