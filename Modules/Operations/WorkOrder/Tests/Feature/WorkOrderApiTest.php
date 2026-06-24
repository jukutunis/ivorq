<?php

namespace Modules\Operations\WorkOrder\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;

class WorkOrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->property = Property::first();
        $this->user = User::first();
        $this->user->properties()->syncWithoutDetaching([
            $this->property->id => [
                'is_default' => true,
                'status' => 'active',
                'joined_at' => now(),
            ]
        ]);
        setPermissionsTeamId($this->property->id);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user->givePermissionTo([
            'workorder.view',
            'workorder.create',
            'workorder.update',
            'workorder.assign',
            'workorder.approve',
            'workorder.close',
            'workorder.manage',
        ]);
    }

    public function test_can_create_work_order()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/operations/work-orders', [
            'title' => 'Fix AC',
            'priority' => 'high',
            'type' => 'corrective',
            'description' => 'The AC is not working properly.',
            'has_guest_impact' => false,
        ], ['X-Property-ID' => $this->property->id]);

        $response->dump(); $response->assertStatus(201);
        $this->assertDatabaseHas('work_orders', [
            'title' => 'Fix AC',
            'status' => 'draft',
            'property_id' => $this->property->id,
        ]);

        // Test priority score logic
        $wo = WorkOrder::find($response->json('id'));
        $this->assertEquals(50, $wo->priority_score); // High = 50
    }

    public function test_emergency_work_order_is_open_immediately()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/operations/work-orders', [
            'title' => 'Water Leak',
            'priority' => 'emergency',
            'type' => 'emergency',
            'description' => 'Huge water leak.',
            'has_guest_impact' => true,
        ], ['X-Property-ID' => $this->property->id]);

        $response->dump(); $response->assertStatus(201);
        $this->assertDatabaseHas('work_orders', [
            'title' => 'Water Leak',
            'status' => 'open', // Emergency skips draft
        ]);

        $wo = WorkOrder::find($response->json('id'));
        $this->assertEquals(150, $wo->priority_score); // Emergency (100) + Guest Impact (50)
    }

    public function test_property_isolation_on_view()
    {
        $wo = WorkOrder::first();

        // Create a property
        $otherProperty = Property::create([
            'id' => \Illuminate\Support\Str::ulid()->toString(),
            'name' => 'Other Property',
            'slug' => 'other-property',
            'code' => 'OTH',
            'company_id' => $this->property->company_id,
            'is_active' => true,
        ]);

        $otherPropertyId = $otherProperty->id;
        $otherUser = User::create([
            'id' => \Illuminate\Support\Str::ulid()->toString(),
            'name' => 'Other User',
            'email' => 'other@ivorq.local',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $otherUser->properties()->syncWithoutDetaching([
            $otherPropertyId => [
                'is_default' => true,
                'status' => 'active',
                'joined_at' => now(),
            ]
        ]);
        setPermissionsTeamId($otherPropertyId);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $otherUser->givePermissionTo('workorder.view');

        $response = $this->actingAs($otherUser)->getJson("/api/v1/operations/work-orders/{$wo->id}", [
            'X-Property-ID' => $otherPropertyId
        ]);
        $response->assertStatus(403);
    }

    public function test_can_update_status()
    {
        $wo = WorkOrder::where('status', 'open')->first();
        $this->assertNotNull($wo);
        $this->assertNotNull($wo->id, 'WO ID is null right after query!');

        // Setup assignment so user is authorized to start work
        \Modules\Operations\WorkOrder\Models\WorkOrderAssignment::create([
            'work_order_id' => $wo->id,
            'user_id' => $this->user->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $wo->update(['status' => 'assigned']);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/operations/work-orders/{$wo->id}/status", [
            'status' => 'in_progress',
        ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('work_orders', [
            'id' => $wo->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_cannot_update_closed_work_order()
    {
        $wo = WorkOrder::where('status', 'open')->first();

        // Setup assignment
        \Modules\Operations\WorkOrder\Models\WorkOrderAssignment::create([
            'work_order_id' => $wo->id,
            'user_id' => $this->user->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $wo->update(['status' => 'closed']);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/operations/work-orders/{$wo->id}/status", [
            'status' => 'in_progress',
        ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(500);
    }
}
