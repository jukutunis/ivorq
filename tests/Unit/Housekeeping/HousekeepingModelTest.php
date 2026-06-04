<?php

namespace Tests\Unit\Housekeeping;

use Modules\Operations\Housekeeping\Enums\AssignmentStatusEnum;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Enums\InspectionTypeEnum;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomTypeEnum;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskTypeEnum;
use Modules\Operations\Housekeeping\Models\ChecklistItem;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\InspectionPhoto;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Models\RoomStatusHistory;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use PHPUnit\Framework\TestCase;

class HousekeepingModelTest extends TestCase
{
    // ── Autoload ─────────────────────────────────────────────────────────────

    public function test_all_model_classes_autoload(): void
    {
        $this->assertInstanceOf(Room::class,             new Room());
        $this->assertInstanceOf(RoomStatusHistory::class, new RoomStatusHistory());
        $this->assertInstanceOf(CleaningTask::class,     new CleaningTask());
        $this->assertInstanceOf(TaskAssignment::class,   new TaskAssignment());
        $this->assertInstanceOf(CleaningChecklist::class, new CleaningChecklist());
        $this->assertInstanceOf(ChecklistItem::class,    new ChecklistItem());
        $this->assertInstanceOf(RoomInspection::class,   new RoomInspection());
        $this->assertInstanceOf(InspectionPhoto::class,  new InspectionPhoto());
    }

    // ── Table names ──────────────────────────────────────────────────────────

    public function test_model_table_names_are_correct(): void
    {
        $this->assertSame('rooms',                 (new Room())->getTable());
        $this->assertSame('room_status_histories', (new RoomStatusHistory())->getTable());
        $this->assertSame('cleaning_tasks',        (new CleaningTask())->getTable());
        $this->assertSame('task_assignments',      (new TaskAssignment())->getTable());
        $this->assertSame('cleaning_checklists',   (new CleaningChecklist())->getTable());
        $this->assertSame('checklist_items',       (new ChecklistItem())->getTable());
        $this->assertSame('room_inspections',      (new RoomInspection())->getTable());
        $this->assertSame('inspection_photos',     (new InspectionPhoto())->getTable());
    }

    // ── ULID keys ─────────────────────────────────────────────────────────────

    public function test_all_models_use_string_primary_key(): void
    {
        foreach ([
            new Room(), new RoomStatusHistory(), new CleaningTask(),
            new TaskAssignment(), new CleaningChecklist(), new ChecklistItem(),
            new RoomInspection(), new InspectionPhoto(),
        ] as $model) {
            $this->assertSame('string', $model->getKeyType(), get_class($model));
            $this->assertFalse($model->getIncrementing(), get_class($model));
        }
    }

    // ── Timestamps ───────────────────────────────────────────────────────────

    public function test_room_status_history_has_no_automatic_timestamps(): void
    {
        $this->assertFalse((new RoomStatusHistory())->timestamps);
    }

    public function test_other_models_have_timestamps(): void
    {
        foreach ([
            new Room(), new CleaningTask(), new TaskAssignment(),
            new CleaningChecklist(), new ChecklistItem(),
            new RoomInspection(), new InspectionPhoto(),
        ] as $model) {
            $this->assertTrue($model->timestamps, get_class($model));
        }
    }

    // ── SoftDeletes ──────────────────────────────────────────────────────────

    public function test_soft_delete_models_use_deleted_at(): void
    {
        foreach ([
            new Room(), new CleaningTask(), new TaskAssignment(),
            new CleaningChecklist(), new RoomInspection(),
        ] as $model) {
            $this->assertTrue(
                in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model)),
                get_class($model) . ' should use SoftDeletes'
            );
        }
    }

    public function test_non_soft_delete_models_do_not_use_deleted_at(): void
    {
        foreach ([
            new RoomStatusHistory(), new ChecklistItem(), new InspectionPhoto(),
        ] as $model) {
            $this->assertFalse(
                in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model)),
                get_class($model) . ' should NOT use SoftDeletes'
            );
        }
    }

    // ── Room enum casts ───────────────────────────────────────────────────────

    public function test_room_casts_room_type_enum(): void
    {
        $casts = (new Room())->getCasts();
        $this->assertArrayHasKey('room_type', $casts);
        $this->assertSame(RoomTypeEnum::class, $casts['room_type']);
    }

    public function test_room_casts_cleanliness_status_enum(): void
    {
        $casts = (new Room())->getCasts();
        $this->assertArrayHasKey('cleanliness_status', $casts);
        $this->assertSame(RoomCleanlinessStatusEnum::class, $casts['cleanliness_status']);
    }

    public function test_room_casts_occupancy_status_enum(): void
    {
        $casts = (new Room())->getCasts();
        $this->assertArrayHasKey('occupancy_status', $casts);
        $this->assertSame(RoomOccupancyStatusEnum::class, $casts['occupancy_status']);
    }

    public function test_room_casts_is_active_as_boolean(): void
    {
        $casts = (new Room())->getCasts();
        $this->assertArrayHasKey('is_active', $casts);
        $this->assertSame('boolean', $casts['is_active']);
    }

    // ── CleaningTask enum casts ───────────────────────────────────────────────

    public function test_cleaning_task_casts_task_type_enum(): void
    {
        $casts = (new CleaningTask())->getCasts();
        $this->assertArrayHasKey('task_type', $casts);
        $this->assertSame(TaskTypeEnum::class, $casts['task_type']);
    }

    public function test_cleaning_task_casts_status_enum(): void
    {
        $casts = (new CleaningTask())->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertSame(TaskStatusEnum::class, $casts['status']);
    }

    public function test_cleaning_task_casts_datetime_fields(): void
    {
        $casts = (new CleaningTask())->getCasts();
        $this->assertArrayHasKey('due_date', $casts);
        $this->assertArrayHasKey('started_at', $casts);
        $this->assertArrayHasKey('completed_at', $casts);
    }

    // ── TaskAssignment enum casts ─────────────────────────────────────────────

    public function test_task_assignment_casts_status_enum(): void
    {
        $casts = (new TaskAssignment())->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertSame(AssignmentStatusEnum::class, $casts['status']);
    }

    // ── CleaningChecklist enum casts ──────────────────────────────────────────

    public function test_cleaning_checklist_casts_task_type_enum(): void
    {
        $casts = (new CleaningChecklist())->getCasts();
        $this->assertArrayHasKey('task_type', $casts);
        $this->assertSame(TaskTypeEnum::class, $casts['task_type']);
    }

    // ── RoomInspection enum casts ─────────────────────────────────────────────

    public function test_room_inspection_casts_inspection_type_enum(): void
    {
        $casts = (new RoomInspection())->getCasts();
        $this->assertArrayHasKey('inspection_type', $casts);
        $this->assertSame(InspectionTypeEnum::class, $casts['inspection_type']);
    }

    public function test_room_inspection_casts_status_enum(): void
    {
        $casts = (new RoomInspection())->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertSame(InspectionStatusEnum::class, $casts['status']);
    }

    public function test_room_inspection_casts_severity_enum(): void
    {
        $casts = (new RoomInspection())->getCasts();
        $this->assertArrayHasKey('inspection_severity', $casts);
        $this->assertSame(InspectionSeverityEnum::class, $casts['inspection_severity']);
    }

    // ── RoomStatusHistory guard ───────────────────────────────────────────────

    public function test_room_status_history_blocks_mass_assignment(): void
    {
        $history = new RoomStatusHistory();
        $this->assertSame(['*'], $history->getGuarded());
    }

    // ── Relationship methods exist ────────────────────────────────────────────

    public function test_room_has_expected_relationship_methods(): void
    {
        $room = new Room();
        $this->assertTrue(method_exists($room, 'property'));
        $this->assertTrue(method_exists($room, 'zone'));
        $this->assertTrue(method_exists($room, 'cleaningTasks'));
        $this->assertTrue(method_exists($room, 'statusHistories'));
        $this->assertTrue(method_exists($room, 'inspections'));
    }

    public function test_cleaning_task_has_expected_relationship_methods(): void
    {
        $task = new CleaningTask();
        $this->assertTrue(method_exists($task, 'property'));
        $this->assertTrue(method_exists($task, 'room'));
        $this->assertTrue(method_exists($task, 'zone'));
        $this->assertTrue(method_exists($task, 'completedBy'));
        $this->assertTrue(method_exists($task, 'assignments'));
        $this->assertTrue(method_exists($task, 'inspections'));
    }

    public function test_task_assignment_has_expected_relationship_methods(): void
    {
        $assignment = new TaskAssignment();
        $this->assertTrue(method_exists($assignment, 'task'));
        $this->assertTrue(method_exists($assignment, 'user'));
        $this->assertTrue(method_exists($assignment, 'department'));
        $this->assertTrue(method_exists($assignment, 'assignedBy'));
    }

    public function test_room_status_history_has_expected_relationship_methods(): void
    {
        $history = new RoomStatusHistory();
        $this->assertTrue(method_exists($history, 'room'));
        $this->assertTrue(method_exists($history, 'performer'));
    }

    public function test_room_inspection_has_expected_relationship_methods(): void
    {
        $inspection = new RoomInspection();
        $this->assertTrue(method_exists($inspection, 'room'));
        $this->assertTrue(method_exists($inspection, 'task'));
        $this->assertTrue(method_exists($inspection, 'inspector'));
        $this->assertTrue(method_exists($inspection, 'photos'));
    }

    public function test_cleaning_checklist_has_expected_relationship_methods(): void
    {
        $checklist = new CleaningChecklist();
        $this->assertTrue(method_exists($checklist, 'items'));
        $this->assertTrue(method_exists($checklist, 'property'));
    }

    public function test_checklist_item_has_expected_relationship_methods(): void
    {
        $item = new ChecklistItem();
        $this->assertTrue(method_exists($item, 'checklist'));
        $this->assertTrue(method_exists($item, 'property'));
    }

    public function test_inspection_photo_has_expected_relationship_methods(): void
    {
        $photo = new InspectionPhoto();
        $this->assertTrue(method_exists($photo, 'inspection'));
        $this->assertTrue(method_exists($photo, 'property'));
    }
}
