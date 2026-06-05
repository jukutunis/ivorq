<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Engineering\Enums\TechnicianAssignmentStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderPriorityEnum;
use Modules\Operations\Engineering\Enums\WorkOrderStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderTypeEnum;
use Modules\Operations\Engineering\Models\WorkOrder;
use Modules\Operations\Engineering\Repositories\WorkOrderRepository;
use Modules\Operations\Engineering\Services\WorkOrderService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesEngineeringData;
use Tests\TestCase;

class WorkOrderModuleTest extends TestCase
{
    use RefreshDatabase, CreatesEngineeringData;

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_create_work_order_status_defaults_to_pending(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = app(WorkOrderService::class)->create([
            'property_id'       => $property->id,
            'work_order_number' => 'WO-MOD-001',
            'title'             => 'Fix lobby AC',
            'work_order_type'   => WorkOrderTypeEnum::Corrective->value,
            'priority'          => WorkOrderPriorityEnum::High->value,
        ]);

        $this->assertSame(WorkOrderStatusEnum::Pending, $wo->status);
        $this->assertDatabaseHas('work_orders', [
            'property_id'       => $property->id,
            'work_order_number' => 'WO-MOD-001',
            'status'            => 'pending',
        ]);
    }

    public function test_create_work_order_writes_status_history_and_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = app(WorkOrderService::class)->create([
            'property_id'       => $property->id,
            'work_order_number' => 'WO-MOD-002',
            'title'             => 'AC Unit Repair',
            'work_order_type'   => WorkOrderTypeEnum::Corrective->value,
            'priority'          => WorkOrderPriorityEnum::Normal->value,
        ]);

        $this->assertDatabaseHas('work_order_status_histories', [
            'work_order_id' => $wo->id,
            'from_status'   => null,
            'to_status'     => WorkOrderStatusEnum::Pending->value,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => WorkOrder::class,
            'subject_id'   => $wo->id,
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_work_order_changes_title_and_strips_status(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrderModel($property);

        $updated = $service->update($wo->id, [
            'title'  => 'Updated Title',
            'status' => WorkOrderStatusEnum::Completed->value,
        ]);

        $this->assertSame('Updated Title', $updated->title);
        $this->assertSame(WorkOrderStatusEnum::Pending, $updated->status);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_delete_work_order_soft_deletes(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrderModel($property);

        $this->assertTrue($service->delete($wo->id));
        $this->assertSoftDeleted('work_orders', ['id' => $wo->id]);
    }

    // ── Uniqueness ────────────────────────────────────────────────────────────

    public function test_work_order_number_must_be_unique_per_property(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(WorkOrderRepository::class);
        $repo->create([
            'property_id'       => $property->id,
            'work_order_number' => 'WO-DUP-001',
            'title'             => 'First',
            'work_order_type'   => WorkOrderTypeEnum::Corrective->value,
            'priority'          => WorkOrderPriorityEnum::Normal->value,
            'status'            => WorkOrderStatusEnum::Pending->value,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $repo->create([
            'property_id'       => $property->id,
            'work_order_number' => 'WO-DUP-001',
            'title'             => 'Duplicate',
            'work_order_type'   => WorkOrderTypeEnum::Corrective->value,
            'priority'          => WorkOrderPriorityEnum::Normal->value,
            'status'            => WorkOrderStatusEnum::Pending->value,
        ]);
    }

    public function test_work_order_number_can_be_reused_across_properties(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'WO-PB10']);
        $admin     = $this->createPropertyAdmin($propertyA);

        $this->actingAs($admin);

        $repo = app(WorkOrderRepository::class);

        $woA = $repo->create(['property_id' => $propertyA->id, 'work_order_number' => 'WO-SHARED', 'title' => 'A', 'work_order_type' => WorkOrderTypeEnum::Corrective->value, 'priority' => WorkOrderPriorityEnum::Normal->value, 'status' => WorkOrderStatusEnum::Pending->value]);
        $woB = $repo->create(['property_id' => $propertyB->id, 'work_order_number' => 'WO-SHARED', 'title' => 'B', 'work_order_type' => WorkOrderTypeEnum::Corrective->value, 'priority' => WorkOrderPriorityEnum::Normal->value, 'status' => WorkOrderStatusEnum::Pending->value]);

        $this->assertNotSame($woA->id, $woB->id);
        $this->assertDatabaseCount('work_orders', 2);
    }

    // ── Status transitions ────────────────────────────────────────────────────

    public function test_change_status_pending_to_cancelled_sets_timestamps(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrderModel($property);

        $updated = $service->changeStatus($wo->id, WorkOrderStatusEnum::Cancelled, 'Not required');

        $this->assertSame(WorkOrderStatusEnum::Cancelled, $updated->status);
        $this->assertSame('Not required', $updated->cancellation_reason);
        $this->assertNotNull($updated->cancelled_at);
        $this->assertNotNull($updated->cancelled_by);
    }

    public function test_change_status_assigned_to_in_progress_sets_started_at(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrderModel($property, ['status' => WorkOrderStatusEnum::Assigned->value]);

        $updated = $service->changeStatus($wo->id, WorkOrderStatusEnum::InProgress);

        $this->assertSame(WorkOrderStatusEnum::InProgress, $updated->status);
        $this->assertNotNull($updated->started_at);
    }

    public function test_change_status_in_progress_to_completed_sets_timestamps_and_completed_by(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrderModel($property, [
            'status'     => WorkOrderStatusEnum::InProgress->value,
            'started_at' => now()->subHour(),
        ]);

        $updated = $service->changeStatus($wo->id, WorkOrderStatusEnum::Completed);

        $this->assertSame(WorkOrderStatusEnum::Completed, $updated->status);
        $this->assertNotNull($updated->completed_at);
        $this->assertSame($admin->id, $updated->completed_by);
        $this->assertNotNull($updated->actual_hours);
    }

    public function test_change_status_to_on_hold_sets_reason(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrderModel($property, ['status' => WorkOrderStatusEnum::Assigned->value]);

        $updated = $service->changeStatus($wo->id, WorkOrderStatusEnum::OnHold, 'Awaiting spare parts');

        $this->assertSame(WorkOrderStatusEnum::OnHold, $updated->status);
        $this->assertSame('Awaiting spare parts', $updated->on_hold_reason);
    }

    public function test_change_status_completed_writes_status_history(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrderModel($property, ['status' => WorkOrderStatusEnum::InProgress->value]);

        $service->changeStatus($wo->id, WorkOrderStatusEnum::Completed);

        $this->assertDatabaseHas('work_order_status_histories', [
            'work_order_id' => $wo->id,
            'to_status'     => WorkOrderStatusEnum::Completed->value,
        ]);
    }

    public function test_invalid_status_transition_throws_validation_exception(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrderModel($property);

        // pending → completed is prohibited
        $this->expectException(ValidationException::class);
        $service->changeStatus($wo->id, WorkOrderStatusEnum::Completed);
    }

    public function test_terminal_to_any_transition_throws_validation_exception(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrderModel($property, ['status' => WorkOrderStatusEnum::Completed->value]);

        $this->expectException(ValidationException::class);
        $service->changeStatus($wo->id, WorkOrderStatusEnum::Cancelled);
    }

    // ── Assign ────────────────────────────────────────────────────────────────

    public function test_assign_creates_active_assignment_and_transitions_to_assigned(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service    = app(WorkOrderService::class);
        $wo         = $this->makeWorkOrderModel($property);

        $assignment = $service->assign($wo->id, [
            'user_id' => $admin->id,
            'role'    => 'lead',
        ]);

        $this->assertSame(TechnicianAssignmentStatusEnum::Active, $assignment->status);
        $this->assertDatabaseHas('technician_assignments', [
            'work_order_id' => $wo->id,
            'user_id'       => $admin->id,
            'role'          => 'lead',
        ]);

        $wo->refresh();
        $this->assertSame(WorkOrderStatusEnum::Assigned, $wo->status);
    }

    public function test_assign_does_not_re_transition_when_already_in_progress(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrderModel($property, ['status' => WorkOrderStatusEnum::InProgress->value]);

        $service->assign($wo->id, ['user_id' => $admin->id, 'role' => 'assistant']);

        $wo->refresh();
        $this->assertSame(WorkOrderStatusEnum::InProgress, $wo->status);
    }

    // ── Approve ───────────────────────────────────────────────────────────────

    public function test_approve_sets_approved_by_and_approved_at(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(WorkOrderService::class);
        $wo      = $this->makeWorkOrderModel($property);

        $updated = $service->approve($wo->id);

        $this->assertSame($admin->id, $updated->approved_by);
        $this->assertNotNull($updated->approved_at);
    }

    // ── Cross-property isolation ──────────────────────────────────────────────

    public function test_cross_property_work_order_policy_denies_view_update_delete(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'WO-PB20']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedEngineeringPermissions();
        app(CurrentPropertyService::class)->setId($propertyA->id);

        $wo = $this->makeWorkOrderModel($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view',   $wo)->denied());
        $this->assertTrue(Gate::inspect('update', $wo)->denied());
        $this->assertTrue(Gate::inspect('delete', $wo)->denied());
    }
}
