<?php

namespace Tests\Unit\Housekeeping;

use Modules\Operations\Housekeeping\Events\CleaningTaskAssigned;
use Modules\Operations\Housekeeping\Events\CleaningTaskCancelled;
use Modules\Operations\Housekeeping\Events\CleaningTaskCompleted;
use Modules\Operations\Housekeeping\Events\CleaningTaskCreated;
use Modules\Operations\Housekeeping\Events\CleaningTaskStarted;
use Modules\Operations\Housekeeping\Events\InspectionCompleted;
use Modules\Operations\Housekeeping\Events\RoomCreated;
use Modules\Operations\Housekeeping\Events\RoomStatusChanged;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class HousekeepingEventTest extends TestCase
{
    // ── Autoload ─────────────────────────────────────────────────────────────

    public function test_all_event_classes_autoload(): void
    {
        $this->assertTrue(class_exists(RoomCreated::class));
        $this->assertTrue(class_exists(RoomStatusChanged::class));
        $this->assertTrue(class_exists(CleaningTaskCreated::class));
        $this->assertTrue(class_exists(CleaningTaskAssigned::class));
        $this->assertTrue(class_exists(CleaningTaskStarted::class));
        $this->assertTrue(class_exists(CleaningTaskCompleted::class));
        $this->assertTrue(class_exists(CleaningTaskCancelled::class));
        $this->assertTrue(class_exists(InspectionCompleted::class));
    }

    // ── Traits ───────────────────────────────────────────────────────────────

    public function test_all_events_use_dispatchable_and_serializes_models(): void
    {
        $events = [
            RoomCreated::class,
            RoomStatusChanged::class,
            CleaningTaskCreated::class,
            CleaningTaskAssigned::class,
            CleaningTaskStarted::class,
            CleaningTaskCompleted::class,
            CleaningTaskCancelled::class,
            InspectionCompleted::class,
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

    // ── Constructor properties are readonly ───────────────────────────────────

    public function test_all_constructor_parameters_are_readonly(): void
    {
        $events = [
            RoomCreated::class,
            RoomStatusChanged::class,
            CleaningTaskCreated::class,
            CleaningTaskAssigned::class,
            CleaningTaskStarted::class,
            CleaningTaskCompleted::class,
            CleaningTaskCancelled::class,
            InspectionCompleted::class,
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

    // ── RoomCreated payload ───────────────────────────────────────────────────

    public function test_room_created_has_room_property(): void
    {
        $rc = new ReflectionClass(RoomCreated::class);
        $ctor = $rc->getConstructor();
        $params = $ctor->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('room', $params[0]->getName());
        $this->assertSame(Room::class, $params[0]->getType()->getName());
    }

    // ── RoomStatusChanged payload ─────────────────────────────────────────────

    public function test_room_status_changed_has_correct_payload(): void
    {
        $rc = new ReflectionClass(RoomStatusChanged::class);
        $params = collect($rc->getConstructor()->getParameters())
            ->keyBy(fn($p) => $p->getName());

        $this->assertTrue($params->has('room'));
        $this->assertSame(Room::class, $params['room']->getType()->getName());

        $this->assertTrue($params->has('statusField'));
        $this->assertSame('string', $params['statusField']->getType()->getName());

        $this->assertTrue($params->has('from'));
        $this->assertTrue($params['from']->allowsNull());

        $this->assertTrue($params->has('to'));
        $this->assertTrue($params['to']->allowsNull());

        $this->assertTrue($params->has('remarks'));
        $this->assertTrue($params['remarks']->allowsNull());
    }

    // ── CleaningTaskCreated payload ───────────────────────────────────────────

    public function test_cleaning_task_created_has_task_property(): void
    {
        $rc = new ReflectionClass(CleaningTaskCreated::class);
        $params = $rc->getConstructor()->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('task', $params[0]->getName());
        $this->assertSame(CleaningTask::class, $params[0]->getType()->getName());
    }

    // ── CleaningTaskAssigned payload ──────────────────────────────────────────

    public function test_cleaning_task_assigned_has_task_and_assignment(): void
    {
        $rc = new ReflectionClass(CleaningTaskAssigned::class);
        $params = collect($rc->getConstructor()->getParameters())
            ->keyBy(fn($p) => $p->getName());

        $this->assertTrue($params->has('task'));
        $this->assertSame(CleaningTask::class, $params['task']->getType()->getName());

        $this->assertTrue($params->has('assignment'));
        $this->assertSame(TaskAssignment::class, $params['assignment']->getType()->getName());
    }

    // ── CleaningTaskStarted / Completed payloads ──────────────────────────────

    public function test_cleaning_task_started_has_task_property(): void
    {
        $params = (new ReflectionClass(CleaningTaskStarted::class))
            ->getConstructor()->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(CleaningTask::class, $params[0]->getType()->getName());
    }

    public function test_cleaning_task_completed_has_task_property(): void
    {
        $params = (new ReflectionClass(CleaningTaskCompleted::class))
            ->getConstructor()->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(CleaningTask::class, $params[0]->getType()->getName());
    }

    // ── CleaningTaskCancelled payload ─────────────────────────────────────────

    public function test_cleaning_task_cancelled_has_task_and_nullable_reason(): void
    {
        $rc = new ReflectionClass(CleaningTaskCancelled::class);
        $params = collect($rc->getConstructor()->getParameters())
            ->keyBy(fn($p) => $p->getName());

        $this->assertTrue($params->has('task'));
        $this->assertSame(CleaningTask::class, $params['task']->getType()->getName());

        $this->assertTrue($params->has('reason'));
        $this->assertTrue($params['reason']->allowsNull());
    }

    // ── InspectionCompleted payload ───────────────────────────────────────────

    public function test_inspection_completed_has_inspection_property(): void
    {
        $rc = new ReflectionClass(InspectionCompleted::class);
        $params = $rc->getConstructor()->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('inspection', $params[0]->getName());
        $this->assertSame(RoomInspection::class, $params[0]->getType()->getName());
    }
}
