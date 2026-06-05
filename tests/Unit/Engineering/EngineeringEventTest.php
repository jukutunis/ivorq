<?php

namespace Tests\Unit\Engineering;

use Modules\Operations\Engineering\Events\AssetRequestApproved;
use Modules\Operations\Engineering\Events\AssetRequestFulfilled;
use Modules\Operations\Engineering\Events\AssetRequestRejected;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskCompleted;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskGenerated;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskOverdue;
use Modules\Operations\Engineering\Events\WorkOrderAssigned;
use Modules\Operations\Engineering\Events\WorkOrderCancelled;
use Modules\Operations\Engineering\Events\WorkOrderCompleted;
use Modules\Operations\Engineering\Events\WorkOrderCreated;
use Modules\Operations\Engineering\Events\WorkOrderOnHold;
use Modules\Operations\Engineering\Events\WorkOrderStarted;
use Modules\Operations\Engineering\Listeners\LogEngineeringActivity;
use Modules\Operations\Engineering\Listeners\RecordWorkOrderHistory;
use Modules\Operations\Engineering\Listeners\UpdatePreventiveMaintenanceSchedule;
use Modules\Operations\Engineering\Models\AssetRequest;
use Modules\Operations\Engineering\Models\PreventiveMaintenanceTask;
use Modules\Operations\Engineering\Models\TechnicianAssignment;
use Modules\Operations\Engineering\Models\WorkOrder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class EngineeringEventTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════════════
    // Autoload
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_event_classes_autoload(): void
    {
        $this->assertTrue(class_exists(WorkOrderCreated::class));
        $this->assertTrue(class_exists(WorkOrderAssigned::class));
        $this->assertTrue(class_exists(WorkOrderStarted::class));
        $this->assertTrue(class_exists(WorkOrderOnHold::class));
        $this->assertTrue(class_exists(WorkOrderCompleted::class));
        $this->assertTrue(class_exists(WorkOrderCancelled::class));
        $this->assertTrue(class_exists(PreventiveMaintenanceTaskGenerated::class));
        $this->assertTrue(class_exists(PreventiveMaintenanceTaskCompleted::class));
        $this->assertTrue(class_exists(PreventiveMaintenanceTaskOverdue::class));
        $this->assertTrue(class_exists(AssetRequestApproved::class));
        $this->assertTrue(class_exists(AssetRequestRejected::class));
        $this->assertTrue(class_exists(AssetRequestFulfilled::class));
    }

    public function test_all_listener_classes_autoload(): void
    {
        $this->assertTrue(class_exists(RecordWorkOrderHistory::class));
        $this->assertTrue(class_exists(LogEngineeringActivity::class));
        $this->assertTrue(class_exists(UpdatePreventiveMaintenanceSchedule::class));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Traits — all events use Dispatchable + SerializesModels
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_events_use_dispatchable_and_serializes_models(): void
    {
        $events = [
            WorkOrderCreated::class,
            WorkOrderAssigned::class,
            WorkOrderStarted::class,
            WorkOrderOnHold::class,
            WorkOrderCompleted::class,
            WorkOrderCancelled::class,
            PreventiveMaintenanceTaskGenerated::class,
            PreventiveMaintenanceTaskCompleted::class,
            PreventiveMaintenanceTaskOverdue::class,
            AssetRequestApproved::class,
            AssetRequestRejected::class,
            AssetRequestFulfilled::class,
        ];

        foreach ($events as $eventClass) {
            $traits = class_uses_recursive($eventClass);
            $this->assertArrayHasKey(
                \Illuminate\Foundation\Events\Dispatchable::class,
                $traits,
                "{$eventClass} must use Dispatchable"
            );
            $this->assertArrayHasKey(
                \Illuminate\Queue\SerializesModels::class,
                $traits,
                "{$eventClass} must use SerializesModels"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Readonly constructor properties
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_constructor_parameters_are_readonly(): void
    {
        $events = [
            WorkOrderCreated::class,
            WorkOrderAssigned::class,
            WorkOrderStarted::class,
            WorkOrderOnHold::class,
            WorkOrderCompleted::class,
            WorkOrderCancelled::class,
            PreventiveMaintenanceTaskGenerated::class,
            PreventiveMaintenanceTaskCompleted::class,
            PreventiveMaintenanceTaskOverdue::class,
            AssetRequestApproved::class,
            AssetRequestRejected::class,
            AssetRequestFulfilled::class,
        ];

        foreach ($events as $eventClass) {
            $rc = new ReflectionClass($eventClass);
            foreach ($rc->getConstructor()->getParameters() as $param) {
                $prop = $rc->getProperty($param->getName());
                $this->assertTrue(
                    $prop->isReadOnly(),
                    "{$eventClass}::\${$param->getName()} must be readonly"
                );
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Event payload shapes
    // ══════════════════════════════════════════════════════════════════════

    public function test_work_order_created_has_single_work_order_payload(): void
    {
        $params = (new ReflectionClass(WorkOrderCreated::class))
            ->getConstructor()->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('workOrder', $params[0]->getName());
        $this->assertSame(WorkOrder::class, $params[0]->getType()->getName());
    }

    public function test_work_order_assigned_has_work_order_and_assignment(): void
    {
        $params = collect(
            (new ReflectionClass(WorkOrderAssigned::class))
                ->getConstructor()->getParameters()
        )->keyBy(fn($p) => $p->getName());

        $this->assertTrue($params->has('workOrder'));
        $this->assertSame(WorkOrder::class, $params['workOrder']->getType()->getName());

        $this->assertTrue($params->has('assignment'));
        $this->assertSame(TechnicianAssignment::class, $params['assignment']->getType()->getName());
    }

    public function test_work_order_started_has_single_work_order_payload(): void
    {
        $params = (new ReflectionClass(WorkOrderStarted::class))
            ->getConstructor()->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame(WorkOrder::class, $params[0]->getType()->getName());
    }

    public function test_work_order_on_hold_has_work_order_and_nullable_reason(): void
    {
        $params = collect(
            (new ReflectionClass(WorkOrderOnHold::class))
                ->getConstructor()->getParameters()
        )->keyBy(fn($p) => $p->getName());

        $this->assertTrue($params->has('workOrder'));
        $this->assertSame(WorkOrder::class, $params['workOrder']->getType()->getName());

        $this->assertTrue($params->has('reason'));
        $this->assertTrue($params['reason']->allowsNull());
    }

    public function test_work_order_completed_has_single_work_order_payload(): void
    {
        $params = (new ReflectionClass(WorkOrderCompleted::class))
            ->getConstructor()->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame(WorkOrder::class, $params[0]->getType()->getName());
    }

    public function test_work_order_cancelled_has_work_order_and_nullable_reason(): void
    {
        $params = collect(
            (new ReflectionClass(WorkOrderCancelled::class))
                ->getConstructor()->getParameters()
        )->keyBy(fn($p) => $p->getName());

        $this->assertTrue($params->has('workOrder'));
        $this->assertSame(WorkOrder::class, $params['workOrder']->getType()->getName());

        $this->assertTrue($params->has('reason'));
        $this->assertTrue($params['reason']->allowsNull());
    }

    public function test_pm_task_events_all_carry_pm_task(): void
    {
        $pmTaskEvents = [
            PreventiveMaintenanceTaskGenerated::class,
            PreventiveMaintenanceTaskCompleted::class,
            PreventiveMaintenanceTaskOverdue::class,
        ];

        foreach ($pmTaskEvents as $eventClass) {
            $params = (new ReflectionClass($eventClass))
                ->getConstructor()->getParameters();

            $this->assertCount(1, $params, "{$eventClass} must have exactly 1 parameter");
            $this->assertSame(
                PreventiveMaintenanceTask::class,
                $params[0]->getType()->getName(),
                "{$eventClass} parameter must be PreventiveMaintenanceTask"
            );
        }
    }

    public function test_asset_request_approved_has_single_request_payload(): void
    {
        $params = (new ReflectionClass(AssetRequestApproved::class))
            ->getConstructor()->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame(AssetRequest::class, $params[0]->getType()->getName());
    }

    public function test_asset_request_rejected_has_request_and_nullable_reason(): void
    {
        $params = collect(
            (new ReflectionClass(AssetRequestRejected::class))
                ->getConstructor()->getParameters()
        )->keyBy(fn($p) => $p->getName());

        $this->assertTrue($params->has('request'));
        $this->assertSame(AssetRequest::class, $params['request']->getType()->getName());

        $this->assertTrue($params->has('reason'));
        $this->assertTrue($params['reason']->allowsNull());
    }

    public function test_asset_request_fulfilled_has_single_request_payload(): void
    {
        $params = (new ReflectionClass(AssetRequestFulfilled::class))
            ->getConstructor()->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame(AssetRequest::class, $params[0]->getType()->getName());
    }

    // ══════════════════════════════════════════════════════════════════════
    // Listener interface — handle() method exists
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_listeners_have_handle_method(): void
    {
        $this->assertTrue(method_exists(RecordWorkOrderHistory::class,            'handle'));
        $this->assertTrue(method_exists(LogEngineeringActivity::class,            'handle'));
        $this->assertTrue(method_exists(UpdatePreventiveMaintenanceSchedule::class, 'handle'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // RecordWorkOrderHistory — handles all WO status events
    // ══════════════════════════════════════════════════════════════════════

    public function test_record_work_order_history_handles_all_work_order_events(): void
    {
        $rc     = new ReflectionClass(RecordWorkOrderHistory::class);
        $method = $rc->getMethod('handle');
        $param  = $method->getParameters()[0];
        $type   = $param->getType();

        // Union type — verify all 6 WO status events are accepted
        $typeNames = collect($type->getTypes())->map(fn($t) => $t->getName())->toArray();

        $this->assertContains(WorkOrderCreated::class,   $typeNames);
        $this->assertContains(WorkOrderAssigned::class,  $typeNames);
        $this->assertContains(WorkOrderStarted::class,   $typeNames);
        $this->assertContains(WorkOrderOnHold::class,    $typeNames);
        $this->assertContains(WorkOrderCompleted::class, $typeNames);
        $this->assertContains(WorkOrderCancelled::class, $typeNames);
    }

    // ══════════════════════════════════════════════════════════════════════
    // UpdatePreventiveMaintenanceSchedule — handles only PM task completed
    // ══════════════════════════════════════════════════════════════════════

    public function test_update_pm_schedule_handles_pm_task_completed(): void
    {
        $rc     = new ReflectionClass(UpdatePreventiveMaintenanceSchedule::class);
        $method = $rc->getMethod('handle');
        $param  = $method->getParameters()[0];

        $this->assertSame(
            PreventiveMaintenanceTaskCompleted::class,
            $param->getType()->getName()
        );
    }
}
