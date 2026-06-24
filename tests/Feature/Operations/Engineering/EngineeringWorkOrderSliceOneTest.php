<?php

namespace Tests\Feature\Operations\Engineering;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Modules\Operations\WorkOrder\Models\WorkOrderAssignment;
use Modules\Operations\WorkOrder\Models\WorkOrderClosure;
use Modules\Operations\WorkOrder\Models\WorkOrderHistory;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Inertia\Testing\AssertableInertia as Assert;

class EngineeringWorkOrderSliceOneTest extends TestCase
{
    use RefreshDatabase, \Tests\Feature\Foundation\Concerns\CreatesFoundationData;

    protected User $supervisor;
    protected User $technician;
    protected User $otherTechnician;
    protected Property $property;
    protected Property $otherProperty;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.testing.ensure_pages_exist', false);

        $this->seed(\Modules\Operations\WorkOrder\Database\Seeders\WorkOrderPermissionSeeder::class);

        $company = $this->createCompany();
        $this->property = $this->createProperty($company);

        $company2 = $this->createCompany();
        $this->otherProperty = $this->createProperty($company2, ['code' => 'OTH']);

        $this->supervisor = $this->createUser($this->property, 'property-admin');
        $this->supervisor->givePermissionTo([
            'workorder.view',
            'workorder.create',
            'workorder.update',
            'workorder.assign',
            'workorder.close',
        ]);

        $this->technician = $this->createUser($this->property, 'staff');
        $this->technician->givePermissionTo([
            'workorder.view',
            'workorder.update',
        ]);

        $this->otherTechnician = $this->createUser($this->otherProperty, 'staff');
        $this->otherTechnician->givePermissionTo([
            'workorder.view',
            'workorder.update',
        ]);
    }

    public function test_property_scoped_requester_creates_draft_work_order()
    {
        $response = $this->actingAs($this->supervisor)->postJson('/api/v1/operations/work-orders', [
            'title' => 'Fix Leak',
            'priority' => 'medium',
            'type' => 'corrective',
            'description' => 'Fixing a leak',
            'has_guest_impact' => false,
        ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('work_orders', [
            'title' => 'Fix Leak',
            'status' => 'draft',
            'property_id' => $this->property->id,
        ]);

        $wo = WorkOrder::find($response->json('id'));
        $this->assertDatabaseHas('work_order_histories', [
            'work_order_id' => $wo->id,
            'action' => 'created',
            'user_id' => $this->supervisor->id,
        ]);
    }

    public function test_authorized_supervisor_assigns_same_property_technician()
    {
        $wo = WorkOrder::create([
            'property_id' => $this->property->id,
            'wo_number' => 'WO-001',
            'title' => 'Fix Lamp',
            'status' => 'draft',
            'priority' => 'medium',
            'type' => 'corrective',
            'created_by' => $this->supervisor->id,
        ]);

        $response = $this->actingAs($this->supervisor)->postJson("/api/v1/operations/work-orders/{$wo->id}/assignments", [
            'user_id' => $this->technician->id,
        ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('work_orders', [
            'id' => $wo->id,
            'status' => 'assigned',
        ]);

        $this->assertDatabaseHas('work_order_assignments', [
            'work_order_id' => $wo->id,
            'user_id' => $this->technician->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('work_order_histories', [
            'work_order_id' => $wo->id,
            'action' => 'assigned',
            'user_id' => $this->supervisor->id,
        ]);
    }

    public function test_assigned_technician_starts_work_order()
    {
        $wo = WorkOrder::create([
            'property_id' => $this->property->id,
            'wo_number' => 'WO-002',
            'title' => 'Fix Door',
            'status' => 'assigned',
            'priority' => 'medium',
            'type' => 'corrective',
            'created_by' => $this->supervisor->id,
        ]);

        WorkOrderAssignment::create([
            'work_order_id' => $wo->id,
            'user_id' => $this->technician->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->technician)->patchJson("/api/v1/operations/work-orders/{$wo->id}/status", [
            'status' => 'in_progress',
        ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('work_orders', [
            'id' => $wo->id,
            'status' => 'in_progress',
        ]);

        $this->assertDatabaseHas('work_order_histories', [
            'work_order_id' => $wo->id,
            'action' => 'started',
            'user_id' => $this->technician->id,
        ]);
    }

    public function test_different_technician_cannot_start_or_resolve()
    {
        $wo = WorkOrder::create([
            'property_id' => $this->property->id,
            'wo_number' => 'WO-003',
            'title' => 'Fix Sink',
            'status' => 'assigned',
            'priority' => 'medium',
            'type' => 'corrective',
        ]);

        WorkOrderAssignment::create([
            'work_order_id' => $wo->id,
            'user_id' => $this->technician->id,
            'status' => 'active',
        ]);

        $otherTechSameProperty = $this->createUser($this->property, 'staff');
        $otherTechSameProperty->givePermissionTo(['workorder.view', 'workorder.update']);

        $response = $this->actingAs($otherTechSameProperty)->patchJson("/api/v1/operations/work-orders/{$wo->id}/status", [
            'status' => 'in_progress',
        ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(500);
    }

    public function test_assigned_technician_resolves_with_non_empty_resolution_evidence()
    {
        $wo = WorkOrder::create([
            'property_id' => $this->property->id,
            'wo_number' => 'WO-004',
            'title' => 'Fix Tap',
            'status' => 'in_progress',
            'priority' => 'medium',
            'type' => 'corrective',
        ]);

        WorkOrderAssignment::create([
            'work_order_id' => $wo->id,
            'user_id' => $this->technician->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->technician)->patchJson("/api/v1/operations/work-orders/{$wo->id}/status", [
            'status' => 'resolved',
            'resolution_notes' => 'Fixed the leaking tap.',
        ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('work_orders', [
            'id' => $wo->id,
            'status' => 'resolved',
        ]);

        $this->assertDatabaseHas('work_order_histories', [
            'work_order_id' => $wo->id,
            'action' => 'resolved',
            'description' => 'Fixed the leaking tap.',
            'user_id' => $this->technician->id,
        ]);
    }

    public function test_missing_resolution_evidence_fails_without_status_mutation()
    {
        $wo = WorkOrder::create([
            'property_id' => $this->property->id,
            'wo_number' => 'WO-005',
            'title' => 'Fix Fan',
            'status' => 'in_progress',
            'priority' => 'medium',
            'type' => 'corrective',
        ]);

        WorkOrderAssignment::create([
            'work_order_id' => $wo->id,
            'user_id' => $this->technician->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->technician)->patchJson("/api/v1/operations/work-orders/{$wo->id}/status", [
            'status' => 'resolved',
            'resolution_notes' => '',
        ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(500);
        $this->assertDatabaseHas('work_orders', [
            'id' => $wo->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_supervisor_closes_resolved_work_order()
    {
        $wo = WorkOrder::create([
            'property_id' => $this->property->id,
            'wo_number' => 'WO-006',
            'title' => 'Fix Heater',
            'status' => 'resolved',
            'priority' => 'medium',
            'type' => 'corrective',
        ]);

        $response = $this->actingAs($this->supervisor)->postJson("/api/v1/operations/work-orders/{$wo->id}/closures", [
            'resolution_notes' => 'Verified resolution of heater issue.',
        ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('work_orders', [
            'id' => $wo->id,
            'status' => 'closed',
        ]);

        $this->assertDatabaseHas('work_order_closures', [
            'work_order_id' => $wo->id,
            'resolution_notes' => 'Verified resolution of heater issue.',
            'closed_by_user_id' => $this->supervisor->id,
        ]);

        $this->assertDatabaseHas('work_order_histories', [
            'work_order_id' => $wo->id,
            'action' => 'closed',
            'user_id' => $this->supervisor->id,
        ]);
    }

    public function test_technician_cannot_close()
    {
        $wo = WorkOrder::create([
            'property_id' => $this->property->id,
            'wo_number' => 'WO-007',
            'title' => 'Fix Bulb',
            'status' => 'resolved',
            'priority' => 'medium',
            'type' => 'corrective',
        ]);

        $response = $this->actingAs($this->technician)->postJson("/api/v1/operations/work-orders/{$wo->id}/closures", [
            'resolution_notes' => 'Attempt closing',
        ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(403);
    }

    public function test_closing_from_in_progress_fails()
    {
        $wo = WorkOrder::create([
            'property_id' => $this->property->id,
            'wo_number' => 'WO-008',
            'title' => 'Fix Pump',
            'status' => 'in_progress',
            'priority' => 'medium',
            'type' => 'corrective',
        ]);

        $response = $this->actingAs($this->supervisor)->postJson("/api/v1/operations/work-orders/{$wo->id}/closures", [
            'resolution_notes' => 'Force close',
        ], ['X-Property-ID' => $this->property->id]);

        $response->assertStatus(500);
    }

    public function test_cross_property_assignment_transition_and_close_fails()
    {
        $wo = WorkOrder::create([
            'property_id' => $this->otherProperty->id,
            'wo_number' => 'WO-009',
            'title' => 'Cross Property',
            'status' => 'draft',
            'priority' => 'medium',
            'type' => 'corrective',
        ]);

        $response = $this->actingAs($this->supervisor)->postJson("/api/v1/operations/work-orders/{$wo->id}/assignments", [
            'user_id' => $this->technician->id,
        ], ['X-Property-ID' => $this->property->id]);
        $response->assertStatus(403);

        $response = $this->actingAs($this->supervisor)->postJson("/api/v1/operations/work-orders/{$wo->id}/assignments", [
            'user_id' => $this->technician->id,
        ], ['X-Property-ID' => $this->otherProperty->id]);
        $response->assertStatus(403);

        $wo->update(['status' => 'assigned']);
        WorkOrderAssignment::create([
            'work_order_id' => $wo->id,
            'user_id' => $this->technician->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->technician)->patchJson("/api/v1/operations/work-orders/{$wo->id}/status", [
            'status' => 'in_progress',
        ], ['X-Property-ID' => $this->property->id]);
        $response->assertStatus(403);
    }

    public function test_engineering_inertia_workspace_returns_property_scoped_data()
    {
        WorkOrder::create([
            'property_id' => $this->property->id,
            'wo_number' => 'WO-A1',
            'title' => 'Property A Work',
            'status' => 'draft',
            'priority' => 'medium',
            'type' => 'corrective',
        ]);

        WorkOrder::create([
            'property_id' => $this->otherProperty->id,
            'wo_number' => 'WO-B1',
            'title' => 'Property B Work',
            'status' => 'draft',
            'priority' => 'medium',
            'type' => 'corrective',
        ]);

        session([
            'current_property_id' => $this->property->id,
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->property->company_id,
        ]);

        $response = $this->actingAs($this->supervisor)->get('/engineering/work-orders');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Ivorq/Engineering/EngineeringWorkspace')
            ->has('workOrders')
            ->has('technicians')
        );

        $props = $response->original->getData()['page']['props'];
        $this->assertCount(1, $props['workOrders']);
        $this->assertEquals('WO-A1', $props['workOrders'][0]['wo_number']);
    }
}
