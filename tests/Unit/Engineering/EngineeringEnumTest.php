<?php

namespace Tests\Unit\Engineering;

use Modules\Operations\Engineering\Enums\AssetRequestStatusEnum;
use Modules\Operations\Engineering\Enums\EngineeringChecklistTypeEnum;
use Modules\Operations\Engineering\Enums\PmFrequencyEnum;
use Modules\Operations\Engineering\Enums\PmStatusEnum;
use Modules\Operations\Engineering\Enums\PmTaskStatusEnum;
use Modules\Operations\Engineering\Enums\TechnicianAssignmentStatusEnum;
use Modules\Operations\Engineering\Enums\TechnicianRoleEnum;
use Modules\Operations\Engineering\Enums\WorkOrderPriorityEnum;
use Modules\Operations\Engineering\Enums\WorkOrderStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderTypeEnum;
use PHPUnit\Framework\TestCase;

class EngineeringEnumTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════════════
    // WorkOrderTypeEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_work_order_type_cases_load_from_value(): void
    {
        $this->assertSame(WorkOrderTypeEnum::Corrective,   WorkOrderTypeEnum::from('corrective'));
        $this->assertSame(WorkOrderTypeEnum::Preventive,   WorkOrderTypeEnum::from('preventive'));
        $this->assertSame(WorkOrderTypeEnum::Emergency,    WorkOrderTypeEnum::from('emergency'));
        $this->assertSame(WorkOrderTypeEnum::Installation, WorkOrderTypeEnum::from('installation'));
        $this->assertSame(WorkOrderTypeEnum::Inspection,   WorkOrderTypeEnum::from('inspection'));
        $this->assertSame(WorkOrderTypeEnum::Renovation,   WorkOrderTypeEnum::from('renovation'));
        $this->assertSame(WorkOrderTypeEnum::GuestRequest, WorkOrderTypeEnum::from('guest_request'));
    }

    public function test_work_order_type_labels(): void
    {
        $this->assertSame('Corrective',    WorkOrderTypeEnum::Corrective->label());
        $this->assertSame('Preventive',    WorkOrderTypeEnum::Preventive->label());
        $this->assertSame('Emergency',     WorkOrderTypeEnum::Emergency->label());
        $this->assertSame('Installation',  WorkOrderTypeEnum::Installation->label());
        $this->assertSame('Inspection',    WorkOrderTypeEnum::Inspection->label());
        $this->assertSame('Renovation',    WorkOrderTypeEnum::Renovation->label());
        $this->assertSame('Guest Request', WorkOrderTypeEnum::GuestRequest->label());
    }

    public function test_work_order_type_has_seven_cases(): void
    {
        $this->assertCount(7, WorkOrderTypeEnum::cases());
    }

    // ══════════════════════════════════════════════════════════════════════
    // WorkOrderStatusEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_work_order_status_cases_load_from_value(): void
    {
        $this->assertSame(WorkOrderStatusEnum::Pending,    WorkOrderStatusEnum::from('pending'));
        $this->assertSame(WorkOrderStatusEnum::Assigned,   WorkOrderStatusEnum::from('assigned'));
        $this->assertSame(WorkOrderStatusEnum::InProgress, WorkOrderStatusEnum::from('in_progress'));
        $this->assertSame(WorkOrderStatusEnum::OnHold,     WorkOrderStatusEnum::from('on_hold'));
        $this->assertSame(WorkOrderStatusEnum::Completed,  WorkOrderStatusEnum::from('completed'));
        $this->assertSame(WorkOrderStatusEnum::Cancelled,  WorkOrderStatusEnum::from('cancelled'));
    }

    public function test_work_order_status_labels(): void
    {
        $this->assertSame('Pending',     WorkOrderStatusEnum::Pending->label());
        $this->assertSame('Assigned',    WorkOrderStatusEnum::Assigned->label());
        $this->assertSame('In Progress', WorkOrderStatusEnum::InProgress->label());
        $this->assertSame('On Hold',     WorkOrderStatusEnum::OnHold->label());
        $this->assertSame('Completed',   WorkOrderStatusEnum::Completed->label());
        $this->assertSame('Cancelled',   WorkOrderStatusEnum::Cancelled->label());
    }

    // ── Valid transitions ──────────────────────────────────────────────────

    public function test_work_order_status_pending_to_assigned_is_valid(): void
    {
        $this->assertTrue(WorkOrderStatusEnum::Pending->canTransitionTo(WorkOrderStatusEnum::Assigned));
    }

    public function test_work_order_status_pending_to_cancelled_is_valid(): void
    {
        $this->assertTrue(WorkOrderStatusEnum::Pending->canTransitionTo(WorkOrderStatusEnum::Cancelled));
    }

    public function test_work_order_status_assigned_to_in_progress_is_valid(): void
    {
        $this->assertTrue(WorkOrderStatusEnum::Assigned->canTransitionTo(WorkOrderStatusEnum::InProgress));
    }

    public function test_work_order_status_assigned_to_on_hold_is_valid(): void
    {
        $this->assertTrue(WorkOrderStatusEnum::Assigned->canTransitionTo(WorkOrderStatusEnum::OnHold));
    }

    public function test_work_order_status_assigned_to_cancelled_is_valid(): void
    {
        $this->assertTrue(WorkOrderStatusEnum::Assigned->canTransitionTo(WorkOrderStatusEnum::Cancelled));
    }

    public function test_work_order_status_in_progress_to_completed_is_valid(): void
    {
        $this->assertTrue(WorkOrderStatusEnum::InProgress->canTransitionTo(WorkOrderStatusEnum::Completed));
    }

    public function test_work_order_status_in_progress_to_on_hold_is_valid(): void
    {
        $this->assertTrue(WorkOrderStatusEnum::InProgress->canTransitionTo(WorkOrderStatusEnum::OnHold));
    }

    public function test_work_order_status_in_progress_to_cancelled_is_valid(): void
    {
        $this->assertTrue(WorkOrderStatusEnum::InProgress->canTransitionTo(WorkOrderStatusEnum::Cancelled));
    }

    public function test_work_order_status_on_hold_to_in_progress_is_valid(): void
    {
        $this->assertTrue(WorkOrderStatusEnum::OnHold->canTransitionTo(WorkOrderStatusEnum::InProgress));
    }

    public function test_work_order_status_on_hold_to_assigned_is_valid(): void
    {
        $this->assertTrue(WorkOrderStatusEnum::OnHold->canTransitionTo(WorkOrderStatusEnum::Assigned));
    }

    public function test_work_order_status_on_hold_to_cancelled_is_valid(): void
    {
        $this->assertTrue(WorkOrderStatusEnum::OnHold->canTransitionTo(WorkOrderStatusEnum::Cancelled));
    }

    // ── Prohibited transitions ─────────────────────────────────────────────

    public function test_work_order_status_pending_to_in_progress_is_prohibited(): void
    {
        $this->assertFalse(WorkOrderStatusEnum::Pending->canTransitionTo(WorkOrderStatusEnum::InProgress));
    }

    public function test_work_order_status_pending_to_completed_is_prohibited(): void
    {
        $this->assertFalse(WorkOrderStatusEnum::Pending->canTransitionTo(WorkOrderStatusEnum::Completed));
    }

    public function test_work_order_status_pending_to_on_hold_is_prohibited(): void
    {
        $this->assertFalse(WorkOrderStatusEnum::Pending->canTransitionTo(WorkOrderStatusEnum::OnHold));
    }

    public function test_work_order_status_assigned_to_completed_is_prohibited(): void
    {
        $this->assertFalse(WorkOrderStatusEnum::Assigned->canTransitionTo(WorkOrderStatusEnum::Completed));
    }

    public function test_work_order_status_assigned_to_pending_is_prohibited(): void
    {
        $this->assertFalse(WorkOrderStatusEnum::Assigned->canTransitionTo(WorkOrderStatusEnum::Pending));
    }

    public function test_work_order_status_in_progress_to_pending_is_prohibited(): void
    {
        $this->assertFalse(WorkOrderStatusEnum::InProgress->canTransitionTo(WorkOrderStatusEnum::Pending));
    }

    public function test_work_order_status_in_progress_to_assigned_is_prohibited(): void
    {
        $this->assertFalse(WorkOrderStatusEnum::InProgress->canTransitionTo(WorkOrderStatusEnum::Assigned));
    }

    public function test_work_order_status_on_hold_to_completed_is_prohibited(): void
    {
        $this->assertFalse(WorkOrderStatusEnum::OnHold->canTransitionTo(WorkOrderStatusEnum::Completed));
    }

    public function test_work_order_status_on_hold_to_pending_is_prohibited(): void
    {
        $this->assertFalse(WorkOrderStatusEnum::OnHold->canTransitionTo(WorkOrderStatusEnum::Pending));
    }

    // ── Terminal states ────────────────────────────────────────────────────

    public function test_work_order_status_completed_is_terminal(): void
    {
        $this->assertTrue(WorkOrderStatusEnum::Completed->isTerminal());
        $this->assertEmpty(WorkOrderStatusEnum::validTransitionsFrom(WorkOrderStatusEnum::Completed));
    }

    public function test_work_order_status_cancelled_is_terminal(): void
    {
        $this->assertTrue(WorkOrderStatusEnum::Cancelled->isTerminal());
        $this->assertEmpty(WorkOrderStatusEnum::validTransitionsFrom(WorkOrderStatusEnum::Cancelled));
    }

    public function test_work_order_status_non_terminal_states_are_not_terminal(): void
    {
        $this->assertFalse(WorkOrderStatusEnum::Pending->isTerminal());
        $this->assertFalse(WorkOrderStatusEnum::Assigned->isTerminal());
        $this->assertFalse(WorkOrderStatusEnum::InProgress->isTerminal());
        $this->assertFalse(WorkOrderStatusEnum::OnHold->isTerminal());
    }

    // ── validTransitionsFrom counts ────────────────────────────────────────

    public function test_work_order_status_valid_transitions_from_pending(): void
    {
        $transitions = WorkOrderStatusEnum::validTransitionsFrom(WorkOrderStatusEnum::Pending);
        $this->assertCount(2, $transitions);
        $this->assertContains(WorkOrderStatusEnum::Assigned,  $transitions);
        $this->assertContains(WorkOrderStatusEnum::Cancelled, $transitions);
    }

    public function test_work_order_status_valid_transitions_from_assigned(): void
    {
        $transitions = WorkOrderStatusEnum::validTransitionsFrom(WorkOrderStatusEnum::Assigned);
        $this->assertCount(3, $transitions);
        $this->assertContains(WorkOrderStatusEnum::InProgress, $transitions);
        $this->assertContains(WorkOrderStatusEnum::OnHold,     $transitions);
        $this->assertContains(WorkOrderStatusEnum::Cancelled,  $transitions);
    }

    public function test_work_order_status_valid_transitions_from_in_progress(): void
    {
        $transitions = WorkOrderStatusEnum::validTransitionsFrom(WorkOrderStatusEnum::InProgress);
        $this->assertCount(3, $transitions);
        $this->assertContains(WorkOrderStatusEnum::Completed, $transitions);
        $this->assertContains(WorkOrderStatusEnum::OnHold,    $transitions);
        $this->assertContains(WorkOrderStatusEnum::Cancelled, $transitions);
    }

    public function test_work_order_status_valid_transitions_from_on_hold(): void
    {
        $transitions = WorkOrderStatusEnum::validTransitionsFrom(WorkOrderStatusEnum::OnHold);
        $this->assertCount(3, $transitions);
        $this->assertContains(WorkOrderStatusEnum::InProgress, $transitions);
        $this->assertContains(WorkOrderStatusEnum::Assigned,   $transitions);
        $this->assertContains(WorkOrderStatusEnum::Cancelled,  $transitions);
    }

    // ══════════════════════════════════════════════════════════════════════
    // WorkOrderPriorityEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_work_order_priority_cases_load_from_value(): void
    {
        $this->assertSame(WorkOrderPriorityEnum::Critical, WorkOrderPriorityEnum::from(1));
        $this->assertSame(WorkOrderPriorityEnum::High,     WorkOrderPriorityEnum::from(2));
        $this->assertSame(WorkOrderPriorityEnum::Normal,   WorkOrderPriorityEnum::from(3));
        $this->assertSame(WorkOrderPriorityEnum::Low,      WorkOrderPriorityEnum::from(4));
    }

    public function test_work_order_priority_labels(): void
    {
        $this->assertSame('Critical', WorkOrderPriorityEnum::Critical->label());
        $this->assertSame('High',     WorkOrderPriorityEnum::High->label());
        $this->assertSame('Normal',   WorkOrderPriorityEnum::Normal->label());
        $this->assertSame('Low',      WorkOrderPriorityEnum::Low->label());
    }

    public function test_work_order_priority_is_integer_backed(): void
    {
        $this->assertSame(1, WorkOrderPriorityEnum::Critical->value);
        $this->assertSame(2, WorkOrderPriorityEnum::High->value);
        $this->assertSame(3, WorkOrderPriorityEnum::Normal->value);
        $this->assertSame(4, WorkOrderPriorityEnum::Low->value);
    }

    public function test_work_order_priority_critical_is_numerically_lowest(): void
    {
        $this->assertLessThan(WorkOrderPriorityEnum::High->value,   WorkOrderPriorityEnum::Critical->value);
        $this->assertLessThan(WorkOrderPriorityEnum::Normal->value, WorkOrderPriorityEnum::High->value);
        $this->assertLessThan(WorkOrderPriorityEnum::Low->value,    WorkOrderPriorityEnum::Normal->value);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TechnicianRoleEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_technician_role_cases_load_from_value(): void
    {
        $this->assertSame(TechnicianRoleEnum::Lead,      TechnicianRoleEnum::from('lead'));
        $this->assertSame(TechnicianRoleEnum::Assistant, TechnicianRoleEnum::from('assistant'));
    }

    public function test_technician_role_labels(): void
    {
        $this->assertSame('Lead Technician', TechnicianRoleEnum::Lead->label());
        $this->assertSame('Assistant',       TechnicianRoleEnum::Assistant->label());
    }

    public function test_technician_role_has_two_cases(): void
    {
        $this->assertCount(2, TechnicianRoleEnum::cases());
    }

    // ══════════════════════════════════════════════════════════════════════
    // TechnicianAssignmentStatusEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_technician_assignment_status_cases_load_from_value(): void
    {
        $this->assertSame(TechnicianAssignmentStatusEnum::Active,    TechnicianAssignmentStatusEnum::from('active'));
        $this->assertSame(TechnicianAssignmentStatusEnum::Completed, TechnicianAssignmentStatusEnum::from('completed'));
        $this->assertSame(TechnicianAssignmentStatusEnum::Relieved,  TechnicianAssignmentStatusEnum::from('relieved'));
        $this->assertSame(TechnicianAssignmentStatusEnum::Cancelled, TechnicianAssignmentStatusEnum::from('cancelled'));
    }

    public function test_technician_assignment_status_labels(): void
    {
        $this->assertSame('Active',    TechnicianAssignmentStatusEnum::Active->label());
        $this->assertSame('Completed', TechnicianAssignmentStatusEnum::Completed->label());
        $this->assertSame('Relieved',  TechnicianAssignmentStatusEnum::Relieved->label());
        $this->assertSame('Cancelled', TechnicianAssignmentStatusEnum::Cancelled->label());
    }

    // ── Valid transitions ──────────────────────────────────────────────────

    public function test_technician_assignment_active_to_completed_is_valid(): void
    {
        $this->assertTrue(TechnicianAssignmentStatusEnum::Active->canTransitionTo(TechnicianAssignmentStatusEnum::Completed));
    }

    public function test_technician_assignment_active_to_relieved_is_valid(): void
    {
        $this->assertTrue(TechnicianAssignmentStatusEnum::Active->canTransitionTo(TechnicianAssignmentStatusEnum::Relieved));
    }

    public function test_technician_assignment_active_to_cancelled_is_valid(): void
    {
        $this->assertTrue(TechnicianAssignmentStatusEnum::Active->canTransitionTo(TechnicianAssignmentStatusEnum::Cancelled));
    }

    // ── Terminal states ────────────────────────────────────────────────────

    public function test_technician_assignment_completed_is_terminal(): void
    {
        $this->assertTrue(TechnicianAssignmentStatusEnum::Completed->isTerminal());
        $this->assertEmpty(TechnicianAssignmentStatusEnum::validTransitionsFrom(TechnicianAssignmentStatusEnum::Completed));
    }

    public function test_technician_assignment_relieved_is_terminal(): void
    {
        $this->assertTrue(TechnicianAssignmentStatusEnum::Relieved->isTerminal());
        $this->assertEmpty(TechnicianAssignmentStatusEnum::validTransitionsFrom(TechnicianAssignmentStatusEnum::Relieved));
    }

    public function test_technician_assignment_cancelled_is_terminal(): void
    {
        $this->assertTrue(TechnicianAssignmentStatusEnum::Cancelled->isTerminal());
        $this->assertEmpty(TechnicianAssignmentStatusEnum::validTransitionsFrom(TechnicianAssignmentStatusEnum::Cancelled));
    }

    public function test_technician_assignment_active_is_not_terminal(): void
    {
        $this->assertFalse(TechnicianAssignmentStatusEnum::Active->isTerminal());
    }

    // ── validTransitionsFrom counts ────────────────────────────────────────

    public function test_technician_assignment_valid_transitions_from_active(): void
    {
        $transitions = TechnicianAssignmentStatusEnum::validTransitionsFrom(TechnicianAssignmentStatusEnum::Active);
        $this->assertCount(3, $transitions);
        $this->assertContains(TechnicianAssignmentStatusEnum::Completed, $transitions);
        $this->assertContains(TechnicianAssignmentStatusEnum::Relieved,  $transitions);
        $this->assertContains(TechnicianAssignmentStatusEnum::Cancelled, $transitions);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PmFrequencyEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_pm_frequency_cases_load_from_value(): void
    {
        $this->assertSame(PmFrequencyEnum::Daily,      PmFrequencyEnum::from('daily'));
        $this->assertSame(PmFrequencyEnum::Weekly,     PmFrequencyEnum::from('weekly'));
        $this->assertSame(PmFrequencyEnum::Monthly,    PmFrequencyEnum::from('monthly'));
        $this->assertSame(PmFrequencyEnum::Quarterly,  PmFrequencyEnum::from('quarterly'));
        $this->assertSame(PmFrequencyEnum::SemiAnnual, PmFrequencyEnum::from('semi_annual'));
        $this->assertSame(PmFrequencyEnum::Annual,     PmFrequencyEnum::from('annual'));
        $this->assertSame(PmFrequencyEnum::Custom,     PmFrequencyEnum::from('custom'));
    }

    public function test_pm_frequency_labels(): void
    {
        $this->assertSame('Daily',        PmFrequencyEnum::Daily->label());
        $this->assertSame('Weekly',       PmFrequencyEnum::Weekly->label());
        $this->assertSame('Monthly',      PmFrequencyEnum::Monthly->label());
        $this->assertSame('Quarterly',    PmFrequencyEnum::Quarterly->label());
        $this->assertSame('Semi-Annual',  PmFrequencyEnum::SemiAnnual->label());
        $this->assertSame('Annual',       PmFrequencyEnum::Annual->label());
        $this->assertSame('Custom (Days)', PmFrequencyEnum::Custom->label());
    }

    public function test_pm_frequency_interval_days_returns_correct_values(): void
    {
        $this->assertSame(1,   PmFrequencyEnum::Daily->intervalDays());
        $this->assertSame(7,   PmFrequencyEnum::Weekly->intervalDays());
        $this->assertSame(30,  PmFrequencyEnum::Monthly->intervalDays());
        $this->assertSame(90,  PmFrequencyEnum::Quarterly->intervalDays());
        $this->assertSame(180, PmFrequencyEnum::SemiAnnual->intervalDays());
        $this->assertSame(365, PmFrequencyEnum::Annual->intervalDays());
    }

    public function test_pm_frequency_custom_interval_days_returns_null(): void
    {
        $this->assertNull(PmFrequencyEnum::Custom->intervalDays());
    }

    public function test_pm_frequency_has_seven_cases(): void
    {
        $this->assertCount(7, PmFrequencyEnum::cases());
    }

    // ══════════════════════════════════════════════════════════════════════
    // PmStatusEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_pm_status_cases_load_from_value(): void
    {
        $this->assertSame(PmStatusEnum::Active,   PmStatusEnum::from('active'));
        $this->assertSame(PmStatusEnum::Inactive, PmStatusEnum::from('inactive'));
        $this->assertSame(PmStatusEnum::Paused,   PmStatusEnum::from('paused'));
    }

    public function test_pm_status_labels(): void
    {
        $this->assertSame('Active',   PmStatusEnum::Active->label());
        $this->assertSame('Inactive', PmStatusEnum::Inactive->label());
        $this->assertSame('Paused',   PmStatusEnum::Paused->label());
    }

    // ── Valid transitions ──────────────────────────────────────────────────

    public function test_pm_status_active_to_paused_is_valid(): void
    {
        $this->assertTrue(PmStatusEnum::Active->canTransitionTo(PmStatusEnum::Paused));
    }

    public function test_pm_status_active_to_inactive_is_valid(): void
    {
        $this->assertTrue(PmStatusEnum::Active->canTransitionTo(PmStatusEnum::Inactive));
    }

    public function test_pm_status_paused_to_active_is_valid(): void
    {
        $this->assertTrue(PmStatusEnum::Paused->canTransitionTo(PmStatusEnum::Active));
    }

    public function test_pm_status_paused_to_inactive_is_valid(): void
    {
        $this->assertTrue(PmStatusEnum::Paused->canTransitionTo(PmStatusEnum::Inactive));
    }

    public function test_pm_status_inactive_to_active_is_valid(): void
    {
        $this->assertTrue(PmStatusEnum::Inactive->canTransitionTo(PmStatusEnum::Active));
    }

    // ── Prohibited transitions ─────────────────────────────────────────────

    public function test_pm_status_inactive_to_paused_is_prohibited(): void
    {
        $this->assertFalse(PmStatusEnum::Inactive->canTransitionTo(PmStatusEnum::Paused));
    }

    public function test_pm_status_active_to_active_is_prohibited(): void
    {
        $this->assertFalse(PmStatusEnum::Active->canTransitionTo(PmStatusEnum::Active));
    }

    // ── No terminal states ─────────────────────────────────────────────────

    public function test_pm_status_no_states_are_terminal(): void
    {
        $this->assertFalse(PmStatusEnum::Active->isTerminal());
        $this->assertFalse(PmStatusEnum::Inactive->isTerminal());
        $this->assertFalse(PmStatusEnum::Paused->isTerminal());
    }

    // ── validTransitionsFrom counts ────────────────────────────────────────

    public function test_pm_status_valid_transitions_from_active(): void
    {
        $transitions = PmStatusEnum::validTransitionsFrom(PmStatusEnum::Active);
        $this->assertCount(2, $transitions);
        $this->assertContains(PmStatusEnum::Paused,   $transitions);
        $this->assertContains(PmStatusEnum::Inactive, $transitions);
    }

    public function test_pm_status_valid_transitions_from_paused(): void
    {
        $transitions = PmStatusEnum::validTransitionsFrom(PmStatusEnum::Paused);
        $this->assertCount(2, $transitions);
        $this->assertContains(PmStatusEnum::Active,   $transitions);
        $this->assertContains(PmStatusEnum::Inactive, $transitions);
    }

    public function test_pm_status_valid_transitions_from_inactive(): void
    {
        $transitions = PmStatusEnum::validTransitionsFrom(PmStatusEnum::Inactive);
        $this->assertCount(1, $transitions);
        $this->assertContains(PmStatusEnum::Active, $transitions);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PmTaskStatusEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_pm_task_status_cases_load_from_value(): void
    {
        $this->assertSame(PmTaskStatusEnum::Scheduled,  PmTaskStatusEnum::from('scheduled'));
        $this->assertSame(PmTaskStatusEnum::Assigned,   PmTaskStatusEnum::from('assigned'));
        $this->assertSame(PmTaskStatusEnum::InProgress, PmTaskStatusEnum::from('in_progress'));
        $this->assertSame(PmTaskStatusEnum::Overdue,    PmTaskStatusEnum::from('overdue'));
        $this->assertSame(PmTaskStatusEnum::Completed,  PmTaskStatusEnum::from('completed'));
        $this->assertSame(PmTaskStatusEnum::Skipped,    PmTaskStatusEnum::from('skipped'));
    }

    public function test_pm_task_status_labels(): void
    {
        $this->assertSame('Scheduled',   PmTaskStatusEnum::Scheduled->label());
        $this->assertSame('Assigned',    PmTaskStatusEnum::Assigned->label());
        $this->assertSame('In Progress', PmTaskStatusEnum::InProgress->label());
        $this->assertSame('Overdue',     PmTaskStatusEnum::Overdue->label());
        $this->assertSame('Completed',   PmTaskStatusEnum::Completed->label());
        $this->assertSame('Skipped',     PmTaskStatusEnum::Skipped->label());
    }

    // ── Valid transitions ──────────────────────────────────────────────────

    public function test_pm_task_scheduled_to_assigned_is_valid(): void
    {
        $this->assertTrue(PmTaskStatusEnum::Scheduled->canTransitionTo(PmTaskStatusEnum::Assigned));
    }

    public function test_pm_task_scheduled_to_skipped_is_valid(): void
    {
        $this->assertTrue(PmTaskStatusEnum::Scheduled->canTransitionTo(PmTaskStatusEnum::Skipped));
    }

    public function test_pm_task_assigned_to_in_progress_is_valid(): void
    {
        $this->assertTrue(PmTaskStatusEnum::Assigned->canTransitionTo(PmTaskStatusEnum::InProgress));
    }

    public function test_pm_task_assigned_to_skipped_is_valid(): void
    {
        $this->assertTrue(PmTaskStatusEnum::Assigned->canTransitionTo(PmTaskStatusEnum::Skipped));
    }

    public function test_pm_task_in_progress_to_completed_is_valid(): void
    {
        $this->assertTrue(PmTaskStatusEnum::InProgress->canTransitionTo(PmTaskStatusEnum::Completed));
    }

    public function test_pm_task_in_progress_to_skipped_is_valid(): void
    {
        $this->assertTrue(PmTaskStatusEnum::InProgress->canTransitionTo(PmTaskStatusEnum::Skipped));
    }

    public function test_pm_task_overdue_to_assigned_is_valid(): void
    {
        $this->assertTrue(PmTaskStatusEnum::Overdue->canTransitionTo(PmTaskStatusEnum::Assigned));
    }

    public function test_pm_task_overdue_to_completed_is_valid(): void
    {
        $this->assertTrue(PmTaskStatusEnum::Overdue->canTransitionTo(PmTaskStatusEnum::Completed));
    }

    public function test_pm_task_overdue_to_skipped_is_valid(): void
    {
        $this->assertTrue(PmTaskStatusEnum::Overdue->canTransitionTo(PmTaskStatusEnum::Skipped));
    }

    // ── Prohibited transitions ─────────────────────────────────────────────

    public function test_pm_task_scheduled_to_in_progress_is_prohibited(): void
    {
        $this->assertFalse(PmTaskStatusEnum::Scheduled->canTransitionTo(PmTaskStatusEnum::InProgress));
    }

    public function test_pm_task_scheduled_to_completed_is_prohibited(): void
    {
        $this->assertFalse(PmTaskStatusEnum::Scheduled->canTransitionTo(PmTaskStatusEnum::Completed));
    }

    public function test_pm_task_assigned_to_scheduled_is_prohibited(): void
    {
        $this->assertFalse(PmTaskStatusEnum::Assigned->canTransitionTo(PmTaskStatusEnum::Scheduled));
    }

    public function test_pm_task_assigned_to_completed_is_prohibited(): void
    {
        $this->assertFalse(PmTaskStatusEnum::Assigned->canTransitionTo(PmTaskStatusEnum::Completed));
    }

    public function test_pm_task_overdue_to_in_progress_is_prohibited(): void
    {
        $this->assertFalse(PmTaskStatusEnum::Overdue->canTransitionTo(PmTaskStatusEnum::InProgress));
    }

    // ── Terminal states ────────────────────────────────────────────────────

    public function test_pm_task_completed_is_terminal(): void
    {
        $this->assertTrue(PmTaskStatusEnum::Completed->isTerminal());
        $this->assertEmpty(PmTaskStatusEnum::validTransitionsFrom(PmTaskStatusEnum::Completed));
    }

    public function test_pm_task_skipped_is_terminal(): void
    {
        $this->assertTrue(PmTaskStatusEnum::Skipped->isTerminal());
        $this->assertEmpty(PmTaskStatusEnum::validTransitionsFrom(PmTaskStatusEnum::Skipped));
    }

    public function test_pm_task_non_terminal_states_are_not_terminal(): void
    {
        $this->assertFalse(PmTaskStatusEnum::Scheduled->isTerminal());
        $this->assertFalse(PmTaskStatusEnum::Assigned->isTerminal());
        $this->assertFalse(PmTaskStatusEnum::InProgress->isTerminal());
        $this->assertFalse(PmTaskStatusEnum::Overdue->isTerminal());
    }

    // ── validTransitionsFrom counts ────────────────────────────────────────

    public function test_pm_task_valid_transitions_from_scheduled(): void
    {
        $transitions = PmTaskStatusEnum::validTransitionsFrom(PmTaskStatusEnum::Scheduled);
        $this->assertCount(2, $transitions);
        $this->assertContains(PmTaskStatusEnum::Assigned, $transitions);
        $this->assertContains(PmTaskStatusEnum::Skipped,  $transitions);
    }

    public function test_pm_task_valid_transitions_from_assigned(): void
    {
        $transitions = PmTaskStatusEnum::validTransitionsFrom(PmTaskStatusEnum::Assigned);
        $this->assertCount(2, $transitions);
        $this->assertContains(PmTaskStatusEnum::InProgress, $transitions);
        $this->assertContains(PmTaskStatusEnum::Skipped,    $transitions);
    }

    public function test_pm_task_valid_transitions_from_in_progress(): void
    {
        $transitions = PmTaskStatusEnum::validTransitionsFrom(PmTaskStatusEnum::InProgress);
        $this->assertCount(2, $transitions);
        $this->assertContains(PmTaskStatusEnum::Completed, $transitions);
        $this->assertContains(PmTaskStatusEnum::Skipped,   $transitions);
    }

    public function test_pm_task_valid_transitions_from_overdue(): void
    {
        $transitions = PmTaskStatusEnum::validTransitionsFrom(PmTaskStatusEnum::Overdue);
        $this->assertCount(3, $transitions);
        $this->assertContains(PmTaskStatusEnum::Assigned,  $transitions);
        $this->assertContains(PmTaskStatusEnum::Completed, $transitions);
        $this->assertContains(PmTaskStatusEnum::Skipped,   $transitions);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AssetRequestStatusEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_asset_request_status_cases_load_from_value(): void
    {
        $this->assertSame(AssetRequestStatusEnum::Pending,   AssetRequestStatusEnum::from('pending'));
        $this->assertSame(AssetRequestStatusEnum::Approved,  AssetRequestStatusEnum::from('approved'));
        $this->assertSame(AssetRequestStatusEnum::Rejected,  AssetRequestStatusEnum::from('rejected'));
        $this->assertSame(AssetRequestStatusEnum::Fulfilled, AssetRequestStatusEnum::from('fulfilled'));
        $this->assertSame(AssetRequestStatusEnum::Cancelled, AssetRequestStatusEnum::from('cancelled'));
    }

    public function test_asset_request_status_labels(): void
    {
        $this->assertSame('Pending',   AssetRequestStatusEnum::Pending->label());
        $this->assertSame('Approved',  AssetRequestStatusEnum::Approved->label());
        $this->assertSame('Rejected',  AssetRequestStatusEnum::Rejected->label());
        $this->assertSame('Fulfilled', AssetRequestStatusEnum::Fulfilled->label());
        $this->assertSame('Cancelled', AssetRequestStatusEnum::Cancelled->label());
    }

    // ── Valid transitions ──────────────────────────────────────────────────

    public function test_asset_request_pending_to_approved_is_valid(): void
    {
        $this->assertTrue(AssetRequestStatusEnum::Pending->canTransitionTo(AssetRequestStatusEnum::Approved));
    }

    public function test_asset_request_pending_to_rejected_is_valid(): void
    {
        $this->assertTrue(AssetRequestStatusEnum::Pending->canTransitionTo(AssetRequestStatusEnum::Rejected));
    }

    public function test_asset_request_pending_to_cancelled_is_valid(): void
    {
        $this->assertTrue(AssetRequestStatusEnum::Pending->canTransitionTo(AssetRequestStatusEnum::Cancelled));
    }

    public function test_asset_request_approved_to_fulfilled_is_valid(): void
    {
        $this->assertTrue(AssetRequestStatusEnum::Approved->canTransitionTo(AssetRequestStatusEnum::Fulfilled));
    }

    public function test_asset_request_approved_to_cancelled_is_valid(): void
    {
        $this->assertTrue(AssetRequestStatusEnum::Approved->canTransitionTo(AssetRequestStatusEnum::Cancelled));
    }

    // ── Prohibited transitions ─────────────────────────────────────────────

    public function test_asset_request_pending_to_fulfilled_is_prohibited(): void
    {
        $this->assertFalse(AssetRequestStatusEnum::Pending->canTransitionTo(AssetRequestStatusEnum::Fulfilled));
    }

    public function test_asset_request_approved_to_rejected_is_prohibited(): void
    {
        $this->assertFalse(AssetRequestStatusEnum::Approved->canTransitionTo(AssetRequestStatusEnum::Rejected));
    }

    public function test_asset_request_approved_to_pending_is_prohibited(): void
    {
        $this->assertFalse(AssetRequestStatusEnum::Approved->canTransitionTo(AssetRequestStatusEnum::Pending));
    }

    public function test_asset_request_rejected_to_approved_is_prohibited(): void
    {
        $this->assertFalse(AssetRequestStatusEnum::Rejected->canTransitionTo(AssetRequestStatusEnum::Approved));
    }

    public function test_asset_request_fulfilled_to_cancelled_is_prohibited(): void
    {
        $this->assertFalse(AssetRequestStatusEnum::Fulfilled->canTransitionTo(AssetRequestStatusEnum::Cancelled));
    }

    // ── Terminal states ────────────────────────────────────────────────────

    public function test_asset_request_rejected_is_terminal(): void
    {
        $this->assertTrue(AssetRequestStatusEnum::Rejected->isTerminal());
        $this->assertEmpty(AssetRequestStatusEnum::validTransitionsFrom(AssetRequestStatusEnum::Rejected));
    }

    public function test_asset_request_fulfilled_is_terminal(): void
    {
        $this->assertTrue(AssetRequestStatusEnum::Fulfilled->isTerminal());
        $this->assertEmpty(AssetRequestStatusEnum::validTransitionsFrom(AssetRequestStatusEnum::Fulfilled));
    }

    public function test_asset_request_cancelled_is_terminal(): void
    {
        $this->assertTrue(AssetRequestStatusEnum::Cancelled->isTerminal());
        $this->assertEmpty(AssetRequestStatusEnum::validTransitionsFrom(AssetRequestStatusEnum::Cancelled));
    }

    public function test_asset_request_pending_is_not_terminal(): void
    {
        $this->assertFalse(AssetRequestStatusEnum::Pending->isTerminal());
    }

    public function test_asset_request_approved_is_not_terminal(): void
    {
        $this->assertFalse(AssetRequestStatusEnum::Approved->isTerminal());
    }

    // ── validTransitionsFrom counts ────────────────────────────────────────

    public function test_asset_request_valid_transitions_from_pending(): void
    {
        $transitions = AssetRequestStatusEnum::validTransitionsFrom(AssetRequestStatusEnum::Pending);
        $this->assertCount(3, $transitions);
        $this->assertContains(AssetRequestStatusEnum::Approved,  $transitions);
        $this->assertContains(AssetRequestStatusEnum::Rejected,  $transitions);
        $this->assertContains(AssetRequestStatusEnum::Cancelled, $transitions);
    }

    public function test_asset_request_valid_transitions_from_approved(): void
    {
        $transitions = AssetRequestStatusEnum::validTransitionsFrom(AssetRequestStatusEnum::Approved);
        $this->assertCount(2, $transitions);
        $this->assertContains(AssetRequestStatusEnum::Fulfilled, $transitions);
        $this->assertContains(AssetRequestStatusEnum::Cancelled, $transitions);
    }

    // ══════════════════════════════════════════════════════════════════════
    // EngineeringChecklistTypeEnum
    // ══════════════════════════════════════════════════════════════════════

    public function test_engineering_checklist_type_cases_load_from_value(): void
    {
        $this->assertSame(EngineeringChecklistTypeEnum::WorkOrder,             EngineeringChecklistTypeEnum::from('work_order'));
        $this->assertSame(EngineeringChecklistTypeEnum::PreventiveMaintenance, EngineeringChecklistTypeEnum::from('preventive_maintenance'));
        $this->assertSame(EngineeringChecklistTypeEnum::Inspection,            EngineeringChecklistTypeEnum::from('inspection'));
    }

    public function test_engineering_checklist_type_labels(): void
    {
        $this->assertSame('Work Order',              EngineeringChecklistTypeEnum::WorkOrder->label());
        $this->assertSame('Preventive Maintenance',  EngineeringChecklistTypeEnum::PreventiveMaintenance->label());
        $this->assertSame('Inspection',              EngineeringChecklistTypeEnum::Inspection->label());
    }

    public function test_engineering_checklist_type_has_three_cases(): void
    {
        $this->assertCount(3, EngineeringChecklistTypeEnum::cases());
    }
}
