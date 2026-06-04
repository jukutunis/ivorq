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
use PHPUnit\Framework\TestCase;

class HousekeepingEnumTest extends TestCase
{
    // ── RoomTypeEnum ────────────────────────────────────────────────────────

    public function test_room_type_enum_cases_load_from_value(): void
    {
        $this->assertSame(RoomTypeEnum::Standard,  RoomTypeEnum::from('standard'));
        $this->assertSame(RoomTypeEnum::Deluxe,    RoomTypeEnum::from('deluxe'));
        $this->assertSame(RoomTypeEnum::Suite,     RoomTypeEnum::from('suite'));
        $this->assertSame(RoomTypeEnum::Villa,     RoomTypeEnum::from('villa'));
        $this->assertSame(RoomTypeEnum::Dormitory, RoomTypeEnum::from('dormitory'));
        $this->assertSame(RoomTypeEnum::Custom,    RoomTypeEnum::from('custom'));
    }

    public function test_room_type_enum_labels(): void
    {
        $this->assertSame('Standard',  RoomTypeEnum::Standard->label());
        $this->assertSame('Deluxe',    RoomTypeEnum::Deluxe->label());
        $this->assertSame('Suite',     RoomTypeEnum::Suite->label());
        $this->assertSame('Villa',     RoomTypeEnum::Villa->label());
        $this->assertSame('Dormitory', RoomTypeEnum::Dormitory->label());
        $this->assertSame('Custom',    RoomTypeEnum::Custom->label());
    }

    // ── RoomCleanlinessStatusEnum ────────────────────────────────────────────

    public function test_cleanliness_enum_cases_load_from_value(): void
    {
        $this->assertSame(RoomCleanlinessStatusEnum::Dirty,     RoomCleanlinessStatusEnum::from('dirty'));
        $this->assertSame(RoomCleanlinessStatusEnum::Clean,     RoomCleanlinessStatusEnum::from('clean'));
        $this->assertSame(RoomCleanlinessStatusEnum::Inspected, RoomCleanlinessStatusEnum::from('inspected'));
    }

    public function test_cleanliness_enum_labels(): void
    {
        $this->assertSame('Dirty',     RoomCleanlinessStatusEnum::Dirty->label());
        $this->assertSame('Clean',     RoomCleanlinessStatusEnum::Clean->label());
        $this->assertSame('Inspected', RoomCleanlinessStatusEnum::Inspected->label());
    }

    public function test_cleanliness_dirty_to_clean_is_valid(): void
    {
        $this->assertTrue(RoomCleanlinessStatusEnum::Dirty->canTransitionTo(RoomCleanlinessStatusEnum::Clean));
    }

    public function test_cleanliness_clean_to_inspected_is_valid(): void
    {
        $this->assertTrue(RoomCleanlinessStatusEnum::Clean->canTransitionTo(RoomCleanlinessStatusEnum::Inspected));
    }

    public function test_cleanliness_clean_to_dirty_is_valid(): void
    {
        // Inspection fail or room re-soiled
        $this->assertTrue(RoomCleanlinessStatusEnum::Clean->canTransitionTo(RoomCleanlinessStatusEnum::Dirty));
    }

    public function test_cleanliness_inspected_to_dirty_is_valid(): void
    {
        // Next guest cycle / checkout
        $this->assertTrue(RoomCleanlinessStatusEnum::Inspected->canTransitionTo(RoomCleanlinessStatusEnum::Dirty));
    }

    public function test_cleanliness_dirty_to_inspected_is_prohibited(): void
    {
        // Must be cleaned before inspection
        $this->assertFalse(RoomCleanlinessStatusEnum::Dirty->canTransitionTo(RoomCleanlinessStatusEnum::Inspected));
    }

    public function test_cleanliness_inspected_to_clean_is_prohibited(): void
    {
        $this->assertFalse(RoomCleanlinessStatusEnum::Inspected->canTransitionTo(RoomCleanlinessStatusEnum::Clean));
    }

    public function test_cleanliness_valid_transitions_from_dirty(): void
    {
        $transitions = RoomCleanlinessStatusEnum::validTransitionsFrom(RoomCleanlinessStatusEnum::Dirty);
        $this->assertCount(1, $transitions);
        $this->assertContains(RoomCleanlinessStatusEnum::Clean, $transitions);
    }

    public function test_cleanliness_valid_transitions_from_clean(): void
    {
        $transitions = RoomCleanlinessStatusEnum::validTransitionsFrom(RoomCleanlinessStatusEnum::Clean);
        $this->assertCount(2, $transitions);
        $this->assertContains(RoomCleanlinessStatusEnum::Inspected, $transitions);
        $this->assertContains(RoomCleanlinessStatusEnum::Dirty, $transitions);
    }

    public function test_cleanliness_valid_transitions_from_inspected(): void
    {
        $transitions = RoomCleanlinessStatusEnum::validTransitionsFrom(RoomCleanlinessStatusEnum::Inspected);
        $this->assertCount(1, $transitions);
        $this->assertContains(RoomCleanlinessStatusEnum::Dirty, $transitions);
    }

    // ── RoomOccupancyStatusEnum ─────────────────────────────────────────────

    public function test_occupancy_enum_cases_load_from_value(): void
    {
        $this->assertSame(RoomOccupancyStatusEnum::Vacant,   RoomOccupancyStatusEnum::from('vacant'));
        $this->assertSame(RoomOccupancyStatusEnum::Occupied, RoomOccupancyStatusEnum::from('occupied'));
        $this->assertSame(RoomOccupancyStatusEnum::Blocked,  RoomOccupancyStatusEnum::from('blocked'));
    }

    public function test_occupancy_enum_labels(): void
    {
        $this->assertSame('Vacant',   RoomOccupancyStatusEnum::Vacant->label());
        $this->assertSame('Occupied', RoomOccupancyStatusEnum::Occupied->label());
        $this->assertSame('Blocked',  RoomOccupancyStatusEnum::Blocked->label());
    }

    public function test_occupancy_null_to_vacant_is_valid(): void
    {
        $this->assertTrue(RoomOccupancyStatusEnum::isValidTransition(null, RoomOccupancyStatusEnum::Vacant));
    }

    public function test_occupancy_null_to_occupied_is_valid(): void
    {
        $this->assertTrue(RoomOccupancyStatusEnum::isValidTransition(null, RoomOccupancyStatusEnum::Occupied));
    }

    public function test_occupancy_null_to_blocked_is_valid(): void
    {
        $this->assertTrue(RoomOccupancyStatusEnum::isValidTransition(null, RoomOccupancyStatusEnum::Blocked));
    }

    public function test_occupancy_vacant_to_occupied_is_valid(): void
    {
        $this->assertTrue(RoomOccupancyStatusEnum::Vacant->canTransitionTo(RoomOccupancyStatusEnum::Occupied));
    }

    public function test_occupancy_vacant_to_blocked_is_valid(): void
    {
        $this->assertTrue(RoomOccupancyStatusEnum::Vacant->canTransitionTo(RoomOccupancyStatusEnum::Blocked));
    }

    public function test_occupancy_occupied_to_vacant_is_valid(): void
    {
        $this->assertTrue(RoomOccupancyStatusEnum::Occupied->canTransitionTo(RoomOccupancyStatusEnum::Vacant));
    }

    public function test_occupancy_blocked_to_vacant_is_valid(): void
    {
        $this->assertTrue(RoomOccupancyStatusEnum::Blocked->canTransitionTo(RoomOccupancyStatusEnum::Vacant));
    }

    public function test_occupancy_occupied_to_blocked_is_prohibited(): void
    {
        $this->assertFalse(RoomOccupancyStatusEnum::Occupied->canTransitionTo(RoomOccupancyStatusEnum::Blocked));
    }

    public function test_occupancy_blocked_to_occupied_is_prohibited(): void
    {
        $this->assertFalse(RoomOccupancyStatusEnum::Blocked->canTransitionTo(RoomOccupancyStatusEnum::Occupied));
    }

    public function test_occupancy_valid_transitions_from_null(): void
    {
        $transitions = RoomOccupancyStatusEnum::validTransitionsFrom(null);
        $this->assertCount(3, $transitions);
        $this->assertContains(RoomOccupancyStatusEnum::Vacant,   $transitions);
        $this->assertContains(RoomOccupancyStatusEnum::Occupied, $transitions);
        $this->assertContains(RoomOccupancyStatusEnum::Blocked,  $transitions);
    }

    public function test_occupancy_valid_transitions_from_vacant(): void
    {
        $transitions = RoomOccupancyStatusEnum::validTransitionsFrom(RoomOccupancyStatusEnum::Vacant);
        $this->assertCount(2, $transitions);
        $this->assertContains(RoomOccupancyStatusEnum::Occupied, $transitions);
        $this->assertContains(RoomOccupancyStatusEnum::Blocked,  $transitions);
    }

    public function test_occupancy_valid_transitions_from_occupied(): void
    {
        $transitions = RoomOccupancyStatusEnum::validTransitionsFrom(RoomOccupancyStatusEnum::Occupied);
        $this->assertCount(1, $transitions);
        $this->assertContains(RoomOccupancyStatusEnum::Vacant, $transitions);
    }

    public function test_occupancy_valid_transitions_from_blocked(): void
    {
        $transitions = RoomOccupancyStatusEnum::validTransitionsFrom(RoomOccupancyStatusEnum::Blocked);
        $this->assertCount(1, $transitions);
        $this->assertContains(RoomOccupancyStatusEnum::Vacant, $transitions);
    }

    public function test_occupancy_is_valid_transition_static_helper_works_with_non_null(): void
    {
        $this->assertTrue(RoomOccupancyStatusEnum::isValidTransition(
            RoomOccupancyStatusEnum::Vacant,
            RoomOccupancyStatusEnum::Occupied,
        ));
        $this->assertFalse(RoomOccupancyStatusEnum::isValidTransition(
            RoomOccupancyStatusEnum::Occupied,
            RoomOccupancyStatusEnum::Blocked,
        ));
    }

    // ── TaskTypeEnum ────────────────────────────────────────────────────────

    public function test_task_type_enum_cases_load_from_value(): void
    {
        $this->assertSame(TaskTypeEnum::CheckoutCleaning, TaskTypeEnum::from('checkout_cleaning'));
        $this->assertSame(TaskTypeEnum::StayoverCleaning, TaskTypeEnum::from('stayover_cleaning'));
        $this->assertSame(TaskTypeEnum::Turndown,         TaskTypeEnum::from('turndown'));
        $this->assertSame(TaskTypeEnum::DeepCleaning,     TaskTypeEnum::from('deep_cleaning'));
        $this->assertSame(TaskTypeEnum::PublicArea,       TaskTypeEnum::from('public_area'));
        $this->assertSame(TaskTypeEnum::SpotCheck,        TaskTypeEnum::from('spot_check'));
        $this->assertSame(TaskTypeEnum::Custom,           TaskTypeEnum::from('custom'));
    }

    public function test_task_type_enum_labels(): void
    {
        $this->assertSame('Checkout Cleaning',  TaskTypeEnum::CheckoutCleaning->label());
        $this->assertSame('Stayover Cleaning',  TaskTypeEnum::StayoverCleaning->label());
        $this->assertSame('Turndown',           TaskTypeEnum::Turndown->label());
        $this->assertSame('Deep Cleaning',      TaskTypeEnum::DeepCleaning->label());
        $this->assertSame('Public Area',        TaskTypeEnum::PublicArea->label());
        $this->assertSame('Spot Check',         TaskTypeEnum::SpotCheck->label());
        $this->assertSame('Custom',             TaskTypeEnum::Custom->label());
    }

    // ── TaskStatusEnum ──────────────────────────────────────────────────────

    public function test_task_status_enum_cases_load_from_value(): void
    {
        $this->assertSame(TaskStatusEnum::Pending,    TaskStatusEnum::from('pending'));
        $this->assertSame(TaskStatusEnum::Assigned,   TaskStatusEnum::from('assigned'));
        $this->assertSame(TaskStatusEnum::InProgress, TaskStatusEnum::from('in_progress'));
        $this->assertSame(TaskStatusEnum::Completed,  TaskStatusEnum::from('completed'));
        $this->assertSame(TaskStatusEnum::Cancelled,  TaskStatusEnum::from('cancelled'));
    }

    public function test_task_status_enum_labels(): void
    {
        $this->assertSame('Pending',     TaskStatusEnum::Pending->label());
        $this->assertSame('Assigned',    TaskStatusEnum::Assigned->label());
        $this->assertSame('In Progress', TaskStatusEnum::InProgress->label());
        $this->assertSame('Completed',   TaskStatusEnum::Completed->label());
        $this->assertSame('Cancelled',   TaskStatusEnum::Cancelled->label());
    }

    public function test_task_status_pending_to_assigned_is_valid(): void
    {
        $this->assertTrue(TaskStatusEnum::Pending->canTransitionTo(TaskStatusEnum::Assigned));
    }

    public function test_task_status_pending_to_cancelled_is_valid(): void
    {
        $this->assertTrue(TaskStatusEnum::Pending->canTransitionTo(TaskStatusEnum::Cancelled));
    }

    public function test_task_status_assigned_to_in_progress_is_valid(): void
    {
        $this->assertTrue(TaskStatusEnum::Assigned->canTransitionTo(TaskStatusEnum::InProgress));
    }

    public function test_task_status_assigned_to_cancelled_is_valid(): void
    {
        $this->assertTrue(TaskStatusEnum::Assigned->canTransitionTo(TaskStatusEnum::Cancelled));
    }

    public function test_task_status_in_progress_to_completed_is_valid(): void
    {
        $this->assertTrue(TaskStatusEnum::InProgress->canTransitionTo(TaskStatusEnum::Completed));
    }

    public function test_task_status_in_progress_to_cancelled_is_valid(): void
    {
        $this->assertTrue(TaskStatusEnum::InProgress->canTransitionTo(TaskStatusEnum::Cancelled));
    }

    public function test_task_status_pending_to_in_progress_is_prohibited(): void
    {
        $this->assertFalse(TaskStatusEnum::Pending->canTransitionTo(TaskStatusEnum::InProgress));
    }

    public function test_task_status_pending_to_completed_is_prohibited(): void
    {
        $this->assertFalse(TaskStatusEnum::Pending->canTransitionTo(TaskStatusEnum::Completed));
    }

    public function test_task_status_completed_is_terminal(): void
    {
        $this->assertTrue(TaskStatusEnum::Completed->isTerminal());
        $this->assertEmpty(TaskStatusEnum::validTransitionsFrom(TaskStatusEnum::Completed));
    }

    public function test_task_status_cancelled_is_terminal(): void
    {
        $this->assertTrue(TaskStatusEnum::Cancelled->isTerminal());
        $this->assertEmpty(TaskStatusEnum::validTransitionsFrom(TaskStatusEnum::Cancelled));
    }

    public function test_task_status_active_states_are_not_terminal(): void
    {
        $this->assertFalse(TaskStatusEnum::Pending->isTerminal());
        $this->assertFalse(TaskStatusEnum::Assigned->isTerminal());
        $this->assertFalse(TaskStatusEnum::InProgress->isTerminal());
    }

    public function test_task_status_valid_transitions_from_pending(): void
    {
        $transitions = TaskStatusEnum::validTransitionsFrom(TaskStatusEnum::Pending);
        $this->assertCount(2, $transitions);
        $this->assertContains(TaskStatusEnum::Assigned,  $transitions);
        $this->assertContains(TaskStatusEnum::Cancelled, $transitions);
    }

    public function test_task_status_valid_transitions_from_assigned(): void
    {
        $transitions = TaskStatusEnum::validTransitionsFrom(TaskStatusEnum::Assigned);
        $this->assertCount(2, $transitions);
        $this->assertContains(TaskStatusEnum::InProgress, $transitions);
        $this->assertContains(TaskStatusEnum::Cancelled,  $transitions);
    }

    public function test_task_status_valid_transitions_from_in_progress(): void
    {
        $transitions = TaskStatusEnum::validTransitionsFrom(TaskStatusEnum::InProgress);
        $this->assertCount(2, $transitions);
        $this->assertContains(TaskStatusEnum::Completed, $transitions);
        $this->assertContains(TaskStatusEnum::Cancelled, $transitions);
    }

    // ── AssignmentStatusEnum ────────────────────────────────────────────────

    public function test_assignment_status_enum_cases_load_from_value(): void
    {
        $this->assertSame(AssignmentStatusEnum::Active,    AssignmentStatusEnum::from('active'));
        $this->assertSame(AssignmentStatusEnum::Completed, AssignmentStatusEnum::from('completed'));
        $this->assertSame(AssignmentStatusEnum::Cancelled, AssignmentStatusEnum::from('cancelled'));
    }

    public function test_assignment_status_enum_labels(): void
    {
        $this->assertSame('Active',    AssignmentStatusEnum::Active->label());
        $this->assertSame('Completed', AssignmentStatusEnum::Completed->label());
        $this->assertSame('Cancelled', AssignmentStatusEnum::Cancelled->label());
    }

    // ── InspectionTypeEnum ──────────────────────────────────────────────────

    public function test_inspection_type_enum_cases_load_from_value(): void
    {
        $this->assertSame(InspectionTypeEnum::Routine,      InspectionTypeEnum::from('routine'));
        $this->assertSame(InspectionTypeEnum::PostCleaning, InspectionTypeEnum::from('post_cleaning'));
        $this->assertSame(InspectionTypeEnum::Checkout,     InspectionTypeEnum::from('checkout'));
        $this->assertSame(InspectionTypeEnum::Checkin,      InspectionTypeEnum::from('checkin'));
        $this->assertSame(InspectionTypeEnum::SpotCheck,    InspectionTypeEnum::from('spot_check'));
    }

    public function test_inspection_type_enum_labels(): void
    {
        $this->assertSame('Routine',       InspectionTypeEnum::Routine->label());
        $this->assertSame('Post Cleaning', InspectionTypeEnum::PostCleaning->label());
        $this->assertSame('Checkout',      InspectionTypeEnum::Checkout->label());
        $this->assertSame('Check-in',      InspectionTypeEnum::Checkin->label());
        $this->assertSame('Spot Check',    InspectionTypeEnum::SpotCheck->label());
    }

    // ── InspectionStatusEnum ────────────────────────────────────────────────

    public function test_inspection_status_enum_cases_load_from_value(): void
    {
        $this->assertSame(InspectionStatusEnum::Pending,    InspectionStatusEnum::from('pending'));
        $this->assertSame(InspectionStatusEnum::InProgress, InspectionStatusEnum::from('in_progress'));
        $this->assertSame(InspectionStatusEnum::Passed,     InspectionStatusEnum::from('passed'));
        $this->assertSame(InspectionStatusEnum::Failed,     InspectionStatusEnum::from('failed'));
        $this->assertSame(InspectionStatusEnum::Deferred,   InspectionStatusEnum::from('deferred'));
    }

    public function test_inspection_status_enum_labels(): void
    {
        $this->assertSame('Pending',     InspectionStatusEnum::Pending->label());
        $this->assertSame('In Progress', InspectionStatusEnum::InProgress->label());
        $this->assertSame('Passed',      InspectionStatusEnum::Passed->label());
        $this->assertSame('Failed',      InspectionStatusEnum::Failed->label());
        $this->assertSame('Deferred',    InspectionStatusEnum::Deferred->label());
    }

    // ── InspectionSeverityEnum ──────────────────────────────────────────────

    public function test_inspection_severity_enum_cases_load_from_value(): void
    {
        $this->assertSame(InspectionSeverityEnum::Minor,    InspectionSeverityEnum::from('minor'));
        $this->assertSame(InspectionSeverityEnum::Major,    InspectionSeverityEnum::from('major'));
        $this->assertSame(InspectionSeverityEnum::Critical, InspectionSeverityEnum::from('critical'));
    }

    public function test_inspection_severity_enum_labels(): void
    {
        $this->assertSame('Minor',    InspectionSeverityEnum::Minor->label());
        $this->assertSame('Major',    InspectionSeverityEnum::Major->label());
        $this->assertSame('Critical', InspectionSeverityEnum::Critical->label());
    }
}
