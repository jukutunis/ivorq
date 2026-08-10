<?php

namespace Tests\Postgres\Operations\Housekeeping;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\AssignmentStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Events\CleaningTaskAssigned;
use Modules\Operations\Housekeeping\Events\CleaningTaskReassigned;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Services\HousekeepingAttendantWorkloadQuery;
use Modules\Operations\Housekeeping\Services\HousekeepingCleaningInspectionReadinessLifecycleService;
use Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverIntakeService;
use Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverWorkspaceQuery;
use Modules\Operations\Housekeeping\Services\HousekeepingTaskDispatchAssignmentService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingCheckoutTurnoverIntakeData;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingRoomReadinessData;
use Tests\PostgresTestCase;

class HousekeepingControlledDispatchAssignmentTest extends PostgresTestCase
{
    use RefreshDatabase;
    use CreatesHousekeepingCheckoutTurnoverIntakeData;
    use CreatesHousekeepingRoomReadinessData;

    private Department $department;
    private Department $otherDepartment;
    private User $attendant;
    private User $secondAttendant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHousekeepingRoomReadinessFixture();
        $this->actor = $this->housekeepingActor;

        foreach ([
            'housekeeping.task.assign',
            'housekeeping.task.view',
            'housekeeping.task.edit',
            'housekeeping.task.start',
            'housekeeping.task.complete',
            'housekeeping.task.cancel',
            'housekeeping.room.view',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->housekeepingActor->givePermissionTo([
            'housekeeping.task.assign',
            'housekeeping.task.view',
            'housekeeping.task.edit',
            'housekeeping.task.start',
            'housekeeping.task.complete',
            'housekeeping.task.cancel',
            'housekeeping.room.view',
        ]);

        $this->department = $this->department($this->property->id, 'Housekeeping', 'HK');
        $this->otherDepartment = $this->department($this->otherProperty->id, 'Other Housekeeping', 'OHK');
        $this->housekeepingActor->update(['department_id' => $this->department->id]);
        $this->attendant = $this->eligibleAttendant('P15 Attendant One');
        $this->secondAttendant = $this->eligibleAttendant('P15 Attendant Two');
    }

    public function test_initial_assignment_is_atomic_audited_evented_and_exactly_replayable(): void
    {
        Event::fake([CleaningTaskAssigned::class]);
        [, $task] = $this->pendingTask('P15-101');
        $key = 'p15-initial-' . Str::uuid();

        $result = $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $this->attendant->id,
            $this->department->id,
            $key,
            '  Priority   checkout  ',
        );
        $replay = $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $this->attendant->id,
            $this->department->id,
            $key,
            'Priority checkout',
        );

        $assignment = $result->assignment->fresh();
        $this->assertSame($assignment->id, $replay->assignment->id);
        $this->assertFalse($result->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertSame(TaskStatusEnum::Assigned, $task->fresh()->status);
        $this->assertSame(AssignmentStatusEnum::Active, $assignment->status);
        $this->assertSame($this->attendant->id, $assignment->user_id);
        $this->assertSame($assignment->user_id, $assignment->attendant_id);
        $this->assertSame($this->housekeepingActor->id, $assignment->assigned_by);
        $this->assertNotNull($assignment->assigned_at);
        $this->assertSame(HousekeepingTaskDispatchAssignmentService::EVIDENCE_VERSION, $assignment->evidence_version);
        $this->assertSame(1, TaskAssignment::where('cleaning_task_id', $task->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'housekeeping_task_assigned')->where('auditable_id', $assignment->id)->count());
        Event::assertDispatchedTimes(CleaningTaskAssigned::class, 1);
        $this->assertArrayNotHasKey('source_hash', $replay->toArray());
        $this->assertArrayNotHasKey('idempotency_key', $replay->toArray());
    }

    public function test_initial_idempotency_and_already_assigned_conflicts_create_zero_new_facts(): void
    {
        [, $task] = $this->pendingTask('P15-102');
        $key = 'p15-conflict-' . Str::uuid();
        $this->assignTask($this->housekeepingActor, $task->id, $this->attendant->id, $this->department->id, $key);
        $before = $this->durableCounts();

        foreach ([
            fn () => $this->assignTask($this->housekeepingActor, $task->id, $this->secondAttendant->id, $this->department->id, $key),
            fn () => $this->assignTask($this->housekeepingActor, $task->id, $this->attendant->id, $this->department->id, 'new-' . $key),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected bounded assignment conflict.');
            } catch (DomainException $exception) {
                $this->assertContains($exception->getMessage(), [
                    HousekeepingTaskDispatchAssignmentService::IDEMPOTENCY_CONFLICT,
                    HousekeepingTaskDispatchAssignmentService::NOT_ELIGIBLE,
                    HousekeepingTaskDispatchAssignmentService::STALE_ACTIVE_ASSIGNMENT,
                    HousekeepingTaskDispatchAssignmentService::SOURCE_CONFLICT,
                ]);
                $this->assertSame($before, $this->durableCounts());
            }
        }
    }

    public function test_pre_start_reassignment_preserves_history_and_replays_exactly(): void
    {
        Event::fake([CleaningTaskReassigned::class]);
        [, $task] = $this->pendingTask('P15-103');
        $initial = $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $this->attendant->id,
            $this->department->id,
            'initial-' . Str::uuid(),
        );
        $key = 'reassign-' . Str::uuid();
        $result = $this->reassignTask(
            $this->housekeepingActor,
            $task->id,
            $this->secondAttendant->id,
            $this->department->id,
            'Operational workload balance',
            $key,
            $initial->assignment->id,
        );
        $replay = $this->reassignTask(
            $this->housekeepingActor,
            $task->id,
            $this->secondAttendant->id,
            $this->department->id,
            'Operational workload balance',
            $key,
            $initial->assignment->id,
        );

        $previous = $initial->assignment->fresh();
        $this->assertSame(AssignmentStatusEnum::Cancelled, $previous->status);
        $this->assertNotNull($previous->closed_at);
        $this->assertSame($this->housekeepingActor->id, $previous->closed_by);
        $this->assertSame('reassigned', $previous->closure_reason);
        $this->assertSame($previous->id, $result->assignment->previous_assignment_id);
        $this->assertSame($result->assignment->id, $replay->assignment->id);
        $this->assertSame(TaskStatusEnum::Assigned, $task->fresh()->status);
        $this->assertSame(1, TaskAssignment::where('cleaning_task_id', $task->id)->where('status', 'active')->count());
        $this->assertSame(2, TaskAssignment::where('cleaning_task_id', $task->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'housekeeping_task_reassigned')->where('auditable_id', $result->assignment->id)->count());
        Event::assertDispatchedTimes(CleaningTaskReassigned::class, 1);
    }

    public function test_reassignment_rejects_same_target_started_terminal_and_changed_replay_material(): void
    {
        [, $task] = $this->pendingTask('P15-104');
        $this->assignTask($this->housekeepingActor, $task->id, $this->attendant->id, $this->department->id, 'initial-' . Str::uuid());
        $before = $this->durableCounts();
        $this->expectBoundedConflict(fn () => $this->reassignTask(
            $this->housekeepingActor,
            $task->id,
            $this->attendant->id,
            $this->department->id,
            'Same target',
            'same-' . Str::uuid(),
        ), $before);
        $this->expectBoundedConflict(fn () => $this->reassignTask(
            $this->housekeepingActor,
            $task->id,
            $this->secondAttendant->id,
            $this->department->id,
            'Stale expected assignment',
            'stale-' . Str::uuid(),
            (string) Str::ulid(),
        ), $before);

        $result = $this->reassignTask(
            $this->housekeepingActor,
            $task->id,
            $this->secondAttendant->id,
            $this->department->id,
            'Coverage change',
            'material-key',
        );
        $alternateActor = $this->eligibleAttendant('P15 Alternate Dispatch Actor');
        $alternateActor->givePermissionTo('housekeeping.task.assign');
        $after = $this->durableCounts();
        foreach ([
            fn () => $this->reassignTask($this->housekeepingActor, $task->id, $this->attendant->id, $this->department->id, 'Coverage change', 'material-key'),
            fn () => $this->reassignTask($this->housekeepingActor, $task->id, $this->secondAttendant->id, $this->otherDepartment->id, 'Coverage change', 'material-key'),
            fn () => $this->reassignTask($alternateActor, $task->id, $this->secondAttendant->id, $this->department->id, 'Coverage change', 'material-key'),
        ] as $changedReplay) {
            $this->expectBoundedConflict($changedReplay, $after);
        }

        $task->update(['status' => TaskStatusEnum::InProgress, 'started_at' => now()]);
        $this->expectBoundedConflict(fn () => $this->reassignTask(
            $this->housekeepingActor,
            $task->id,
            $this->attendant->id,
            $this->department->id,
            'Started task',
            'started-' . Str::uuid(),
        ), $this->durableCounts());
        $this->assertSame($result->assignment->id, TaskAssignment::where('cleaning_task_id', $task->id)->where('status', 'active')->value('id'));

        [, $completedTask] = $this->pendingTask('P15-104-C');
        $this->assignTask($this->housekeepingActor, $completedTask->id, $this->housekeepingActor->id, $this->department->id, 'completed-initial-' . Str::uuid());
        $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $completedTask->id, TaskStatusEnum::InProgress);
        $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $completedTask->id, TaskStatusEnum::Completed, 'Terminal reassignment proof.');
        $this->expectBoundedConflict(fn () => $this->reassignTask(
            $this->housekeepingActor,
            $completedTask->id,
            $this->attendant->id,
            $this->department->id,
            'Completed task',
            'completed-' . Str::uuid(),
        ), $this->durableCounts());

        [, $cancelledTask] = $this->pendingTask('P15-104-X');
        $this->assignTask($this->housekeepingActor, $cancelledTask->id, $this->attendant->id, $this->department->id, 'cancelled-initial-' . Str::uuid());
        $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $cancelledTask->id, TaskStatusEnum::Cancelled);
        $this->expectBoundedConflict(fn () => $this->reassignTask(
            $this->housekeepingActor,
            $cancelledTask->id,
            $this->secondAttendant->id,
            $this->department->id,
            'Cancelled task',
            'cancelled-' . Str::uuid(),
        ), $this->durableCounts());
    }

    public function test_service_enforces_authorization_property_and_target_eligibility_without_mutation(): void
    {
        [, $task] = $this->pendingTask('P15-105');
        $unauthorized = $this->eligibleAttendant('P15 Unauthorized');
        $before = $this->durableCounts();

        try {
            $this->assignTask($unauthorized, $task->id, $this->attendant->id, $this->department->id, 'unauthorized');
            $this->fail('Expected authorization denial.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(HousekeepingTaskDispatchAssignmentService::NOT_AUTHORIZED, $exception->getMessage());
            $this->assertSame($before, $this->durableCounts());
        }

        $inactive = $this->eligibleAttendant('P15 Inactive');
        $inactive->update(['is_active' => false]);
        $beforeInactive = $this->durableCounts();
        $this->expectBoundedConflict(fn () => $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $inactive->id,
            $this->department->id,
            'inactive-target',
        ), $beforeInactive);

        $wrongDepartmentTarget = $this->eligibleAttendant('P15 Wrong Department');
        $beforeWrongDepartment = $this->durableCounts();
        $this->expectBoundedConflict(fn () => $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $wrongDepartmentTarget->id,
            $this->otherDepartment->id,
            'wrong-department',
        ), $beforeWrongDepartment);

        [, $otherTask] = $this->pendingTask('P15-X', $this->otherProperty->id);
        try {
            $this->assignTask($this->housekeepingActor, $otherTask->id, $this->attendant->id, $this->department->id, 'cross-property');
            $this->fail('Expected non-disclosing cross-Property denial.');
        } catch (HttpException $exception) {
            $this->assertSame(HousekeepingTaskDispatchAssignmentService::NOT_AUTHORIZED, $exception->getMessage());
        }
        $this->assertSame(0, TaskAssignment::where('property_id', $this->property->id)->count());
    }

    public function test_service_independently_rechecks_actor_context_and_every_target_relationship(): void
    {
        [, $task] = $this->pendingTask('P15-105B');

        $permissionOnly = $this->hkUser('P15 Permission Only', 'p15-permission-only@example.test');
        $permissionOnly->givePermissionTo('housekeeping.task.assign');
        $this->expectBoundedDenial(fn () => $this->assignTask(
            $permissionOnly,
            $task->id,
            $this->attendant->id,
            $this->department->id,
            'permission-without-policy-' . Str::uuid(),
        ), $this->durableCounts());

        $policyContextOnly = $this->eligibleAttendant('P15 Policy Context Only');
        $this->expectBoundedDenial(fn () => $this->assignTask(
            $policyContextOnly,
            $task->id,
            $this->attendant->id,
            $this->department->id,
            'policy-context-without-permission-' . Str::uuid(),
        ), $this->durableCounts());

        DB::table('properties')->where('id', $this->property->id)->update(['is_active' => false]);
        $this->expectBoundedDenial(fn () => $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $this->attendant->id,
            $this->department->id,
            'inactive-property-' . Str::uuid(),
        ), $this->durableCounts());
        DB::table('properties')->where('id', $this->property->id)->update(['is_active' => true]);

        session(['active_company_id' => $this->otherCompany->id]);
        $this->expectBoundedDenial(fn () => $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $this->attendant->id,
            $this->department->id,
            'wrong-company-' . Str::uuid(),
        ), $this->durableCounts());
        session(['active_company_id' => $this->company->id]);

        $crossPropertyTarget = $this->hkUser('P15 Cross Property Target', 'p15-cross-property-target@example.test');
        $crossPropertyTarget->update(['department_id' => $this->otherDepartment->id]);
        $this->hkAttachProperty($crossPropertyTarget, $this->otherProperty);
        $this->expectBoundedConflict(fn () => $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $crossPropertyTarget->id,
            $this->department->id,
            'cross-property-target-' . Str::uuid(),
        ), $this->durableCounts());

        $noMembership = $this->hkUser('P15 Target No Membership', 'p15-target-no-membership@example.test');
        $noMembership->update(['department_id' => $this->department->id]);
        $this->expectBoundedConflict(fn () => $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $noMembership->id,
            $this->department->id,
            'no-membership-' . Str::uuid(),
        ), $this->durableCounts());

        $inactiveMembership = $this->eligibleAttendant('P15 Target Inactive Membership');
        DB::table('property_user')->where('property_id', $this->property->id)->where('user_id', $inactiveMembership->id)->update(['status' => 'inactive']);
        $this->expectBoundedConflict(fn () => $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $inactiveMembership->id,
            $this->department->id,
            'inactive-membership-' . Str::uuid(),
        ), $this->durableCounts());

        $softDeleted = $this->eligibleAttendant('P15 Soft Deleted Target');
        $softDeleted->delete();
        $this->expectBoundedConflict(fn () => $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $softDeleted->id,
            $this->department->id,
            'soft-deleted-target-' . Str::uuid(),
        ), $this->durableCounts());

        $inactiveDepartment = $this->department($this->property->id, 'Inactive Housekeeping', 'IHK');
        DB::table('departments')->where('id', $inactiveDepartment->id)->update(['is_active' => false]);
        $inactiveDepartmentTarget = $this->eligibleAttendant('P15 Inactive Department Target');
        $inactiveDepartmentTarget->update(['department_id' => $inactiveDepartment->id]);
        $this->expectBoundedConflict(fn () => $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $inactiveDepartmentTarget->id,
            $inactiveDepartment->id,
            'inactive-department-' . Str::uuid(),
        ), $this->durableCounts());

        $deletedDepartment = $this->department($this->property->id, 'Deleted Housekeeping', 'DHK');
        DB::table('departments')->where('id', $deletedDepartment->id)->update(['deleted_at' => now()]);
        $deletedDepartmentTarget = $this->eligibleAttendant('P15 Deleted Department Target');
        $deletedDepartmentTarget->update(['department_id' => $deletedDepartment->id]);
        $this->expectBoundedConflict(fn () => $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $deletedDepartmentTarget->id,
            $deletedDepartment->id,
            'deleted-department-' . Str::uuid(),
        ), $this->durableCounts());

        $alternateDepartment = $this->department($this->property->id, 'Alternate Housekeeping', 'AHK');
        $this->expectBoundedConflict(fn () => $this->assignTask(
            $this->housekeepingActor,
            $task->id,
            $this->attendant->id,
            $alternateDepartment->id,
            'department-mismatch-' . Str::uuid(),
        ), $this->durableCounts());
    }

    public function test_task_completion_and_cancellation_close_assignment_atomically(): void
    {
        [, $completionTask] = $this->pendingTask('P15-106');
        $this->assignTask(
            $this->housekeepingActor,
            $completionTask->id,
            $this->housekeepingActor->id,
            $this->department->id,
            'complete-' . Str::uuid(),
        );
        $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $completionTask->id, TaskStatusEnum::InProgress);
        $this->assertSame(1, TaskAssignment::where('cleaning_task_id', $completionTask->id)->where('status', 'active')->count());
        $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $completionTask->id, TaskStatusEnum::Completed, 'Ready for inspection.');
        $completed = TaskAssignment::where('cleaning_task_id', $completionTask->id)->firstOrFail();
        $this->assertSame(AssignmentStatusEnum::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertNotNull($completed->closed_at);
        $this->assertSame(0, TaskAssignment::where('cleaning_task_id', $completionTask->id)->where('status', 'active')->count());
        $completedEvidence = (array) DB::table('housekeeping_task_assignments')->where('id', $completed->id)->first([
            'status', 'completed_at', 'closed_at', 'closed_by', 'closure_reason', 'updated_at',
        ]);
        $beforeReplay = $this->durableCounts();
        $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $completionTask->id, TaskStatusEnum::Completed, 'Ready for inspection.');
        $this->assertSame($completedEvidence, (array) DB::table('housekeeping_task_assignments')->where('id', $completed->id)->first(array_keys($completedEvidence)));
        $this->assertSame($beforeReplay, $this->durableCounts());

        [, $cancelTask] = $this->pendingTask('P15-107');
        $this->assignTask(
            $this->housekeepingActor,
            $cancelTask->id,
            $this->attendant->id,
            $this->department->id,
            'cancel-' . Str::uuid(),
        );
        $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $cancelTask->id, TaskStatusEnum::Cancelled);
        $cancelled = TaskAssignment::where('cleaning_task_id', $cancelTask->id)->firstOrFail();
        $this->assertSame(AssignmentStatusEnum::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->closed_at);
        $this->assertSame(TaskStatusEnum::Cancelled, $cancelTask->fresh()->status);
        $this->assertSame(0, TaskAssignment::where('cleaning_task_id', $cancelTask->id)->where('status', 'active')->count());

        [, $pendingCancellation] = $this->pendingTask('P15-108');
        $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $pendingCancellation->id, TaskStatusEnum::Cancelled);
        $this->assertSame(0, TaskAssignment::where('cleaning_task_id', $pendingCancellation->id)->count());
    }

    public function test_workload_projection_is_current_property_scoped_deterministic_and_privacy_minimized(): void
    {
        [, $taskOne] = $this->pendingTask('P15-109', $this->property->id, ['priority' => 'rush', 'credits' => 1.5]);
        [, $taskTwo] = $this->pendingTask('P15-110', $this->property->id, ['credits' => 2]);
        $this->assignTask($this->housekeepingActor, $taskOne->id, $this->attendant->id, $this->department->id, 'workload-1');
        $this->assignTask($this->housekeepingActor, $taskTwo->id, $this->secondAttendant->id, $this->department->id, 'workload-2');
        $taskTwo->update(['status' => TaskStatusEnum::InProgress, 'started_at' => now()]);

        $inactiveUser = $this->eligibleAttendant('P15 Workload Inactive User');
        $inactiveUser->update(['is_active' => false]);
        $inactiveMembership = $this->eligibleAttendant('P15 Workload Inactive Membership');
        DB::table('property_user')->where('property_id', $this->property->id)->where('user_id', $inactiveMembership->id)->update(['status' => 'inactive']);
        $otherPropertyUser = $this->hkUser('P15 Workload Other Property', 'p15-workload-other@example.test');
        $otherPropertyUser->update(['department_id' => $this->otherDepartment->id]);
        $this->hkAttachProperty($otherPropertyUser, $this->otherProperty);

        $before = $this->durableCounts();
        $rows = app(HousekeepingAttendantWorkloadQuery::class)->forProperty($this->property->id);
        $this->assertSame($before, $this->durableCounts());
        $first = collect($rows)->firstWhere('user_id', $this->attendant->id);
        $second = collect($rows)->firstWhere('user_id', $this->secondAttendant->id);
        $this->assertSame(1, $first['assigned_not_started_count']);
        $this->assertSame(1, $first['active_assignment_count']);
        $this->assertSame(1, $first['rush_assignment_count']);
        $this->assertSame(1.5, $first['active_credits']);
        $this->assertSame(1, $second['in_progress_count']);
        $this->assertSame(1, $second['active_assignment_count']);
        $this->assertSame(2.0, $second['active_credits']);
        $userIds = array_column($rows, 'user_id');
        $this->assertNotContains($inactiveUser->id, $userIds);
        $this->assertNotContains($inactiveMembership->id, $userIds);
        $this->assertNotContains($otherPropertyUser->id, $userIds);
        $serialized = json_encode($rows, JSON_THROW_ON_ERROR);
        foreach (['email', 'phone', 'password', 'token', 'source_hash', 'idempotency_key', 'payroll'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_checkout_turnover_workspace_projects_bounded_actions_and_http_replay_without_trusting_browser_authority(): void
    {
        $source = $this->p11CheckoutSource(
            $this->property,
            $this->p11Room($this->property, ['room_number' => 'P15-WORKSPACE']),
        );
        app(HousekeepingCheckoutTurnoverIntakeService::class)->consumeNextAvailable($this->property->id, 60);
        $task = CleaningTask::where('property_id', $this->property->id)->where('task_type', 'checkout_cleaning')->latest('created_at')->firstOrFail();
        $url = '/operations/housekeeping/checkout-turnovers?selected=' . $source['handoff']->id;
        $session = $this->hkPropertySession($this->property);

        $initialWorkspace = $this->withSession($session)->actingAs($this->housekeepingActor, 'web')->get($url);
        $initialWorkspace->assertOk();
        $initialWorkspace->assertInertia(fn (Assert $page) => $page
                ->where('selected_turnover.assignment_actions.can_assign', true)
                ->where('selected_turnover.assignment_actions.can_reassign', false)
                ->has('selected_turnover.eligible_attendants')
                ->has('selected_turnover.attendant_workload'));

        $key = 'workspace-' . Str::uuid();
        $payload = [
            'user_id' => $this->housekeepingActor->id,
            'department_id' => $this->department->id,
            'idempotency_key' => $key,
            'expected_active_assignment_id' => null,
        ];
        $first = $this->withSession($session)->actingAs($this->housekeepingActor, 'web')->postJson("/operations/cleaning-tasks/{$task->id}/assign", $payload);
        $first->assertCreated()->assertJsonPath('replayed', false);
        $this->withSession($session)->actingAs($this->housekeepingActor, 'web')->postJson("/operations/cleaning-tasks/{$task->id}/assign", $payload)
            ->assertOk()
            ->assertJsonPath('assignment_id', $first->json('assignment_id'))
            ->assertJsonPath('replayed', true)
            ->assertJsonMissingPath('source_hash')
            ->assertJsonMissingPath('idempotency_key');

        $assignedWorkspace = $this->withSession($session)->actingAs($this->housekeepingActor, 'web')->get($url);
        $assignedWorkspace->assertOk();
        $assignedWorkspace->assertInertia(fn (Assert $page) => $page
                ->where('selected_turnover.assignment_actions.can_assign', false)
                ->where('selected_turnover.assignment_actions.can_reassign', true)
                ->where('selected_turnover.active_assignment.user_id', $this->housekeepingActor->id));

        $this->withSession($session)->actingAs($this->housekeepingActor, 'web')->postJson("/operations/cleaning-tasks/{$task->id}/assign", [
            'user_id' => $this->secondAttendant->id,
            'department_id' => $this->department->id,
            'idempotency_key' => 'forged-' . Str::uuid(),
            'expected_active_assignment_id' => $first->json('assignment_id'),
            'property_id' => $this->otherProperty->id,
            'assigned_by' => $this->secondAttendant->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('request');

        $viewer = $this->eligibleAttendant('P15 Read Only Viewer');
        $viewer->givePermissionTo(['housekeeping.room.view', 'housekeeping.task.view']);
        $navigation = ['room' => true, 'cleaning_task' => true, 'room_readiness' => true];
        $unauthorizedProjection = app(HousekeepingCheckoutTurnoverWorkspaceQuery::class)->forProperty(
            $this->property->id,
            ['selected' => $source['handoff']->id],
            $navigation,
            $viewer,
        )['selected_turnover'];
        $this->assertFalse($unauthorizedProjection['assignment_actions']['can_assign']);
        $this->assertFalse($unauthorizedProjection['assignment_actions']['can_reassign']);
        $this->assertSame(['ASSIGNMENT_PERMISSION_REQUIRED'], $unauthorizedProjection['assignment_actions']['assignment_blockers']);
        $this->assertSame([], $unauthorizedProjection['eligible_attendants']);
        $this->assertSame([], $unauthorizedProjection['attendant_workload']);

        $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $task->id, TaskStatusEnum::InProgress);
        $startedProjection = app(HousekeepingCheckoutTurnoverWorkspaceQuery::class)->forProperty(
            $this->property->id,
            ['selected' => $source['handoff']->id],
            $navigation,
            $this->housekeepingActor,
        )['selected_turnover'];
        $this->assertFalse($startedProjection['assignment_actions']['can_assign']);
        $this->assertFalse($startedProjection['assignment_actions']['can_reassign']);
        $this->assertSame(['TASK_ALREADY_STARTED'], $startedProjection['assignment_actions']['assignment_blockers']);

        $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $task->id, TaskStatusEnum::Completed, 'Workspace terminal projection proof.');
        $terminalProjection = app(HousekeepingCheckoutTurnoverWorkspaceQuery::class)->forProperty(
            $this->property->id,
            ['selected' => $source['handoff']->id],
            $navigation,
            $this->housekeepingActor,
        )['selected_turnover'];
        $this->assertFalse($terminalProjection['assignment_actions']['can_assign']);
        $this->assertFalse($terminalProjection['assignment_actions']['can_reassign']);
        $this->assertSame(['TASK_TERMINAL'], $terminalProjection['assignment_actions']['assignment_blockers']);
    }

    private function department(string $propertyId, string $name, string $code): Department
    {
        return Department::create([
            'property_id' => $propertyId,
            'name' => $name,
            'code' => $code . Str::upper(Str::random(5)),
            'is_active' => true,
        ]);
    }

    private function eligibleAttendant(string $name): User
    {
        $user = $this->hkUser($name, Str::slug($name) . '@example.test');
        $user->update(['department_id' => $this->department->id]);
        $this->hkAttachProperty($user, $this->property);

        return $user;
    }

    /** @return array{0: Room, 1: CleaningTask} */
    private function pendingTask(string $roomNumber, ?string $propertyId = null, array $overrides = []): array
    {
        $property = $propertyId === $this->otherProperty->id ? $this->otherProperty : $this->property;
        $room = Room::withoutGlobalScopes()->findOrFail($this->hkDirtyRoom($property, $roomNumber));
        $task = CleaningTask::create(array_merge([
            'property_id' => $property->id,
            'room_id' => $room->id,
            'task_code' => 'TASK-' . $roomNumber,
            'title' => 'Checkout clean ' . $roomNumber,
            'task_type' => 'checkout_cleaning',
            'status' => TaskStatusEnum::Pending,
            'priority' => 'normal',
            'credits' => 1,
        ], $overrides));

        return [$room, $task];
    }

    private function dispatch(): HousekeepingTaskDispatchAssignmentService
    {
        return app(HousekeepingTaskDispatchAssignmentService::class);
    }

    private function assignTask(
        User $actor,
        string $taskId,
        string $attendantId,
        string $departmentId,
        string $idempotencyKey,
        ?string $ignoredLegacyReason = null,
    ): \Modules\Operations\Housekeeping\ValueObjects\HousekeepingTaskAssignmentResult {
        return $this->dispatch()->assignOrReassign(
            $actor,
            $taskId,
            $attendantId,
            $departmentId,
            $idempotencyKey,
            null,
        );
    }

    private function reassignTask(
        User $actor,
        string $taskId,
        string $attendantId,
        string $departmentId,
        string $ignoredLegacyReason,
        string $idempotencyKey,
        ?string $expectedActiveAssignmentId = null,
    ): \Modules\Operations\Housekeeping\ValueObjects\HousekeepingTaskAssignmentResult {
        $expectedActiveAssignmentId ??= TaskAssignment::withoutGlobalScopes()
            ->where('cleaning_task_id', $taskId)
            ->where('status', AssignmentStatusEnum::Active)
            ->value('id');

        return $this->dispatch()->assignOrReassign(
            $actor,
            $taskId,
            $attendantId,
            $departmentId,
            $idempotencyKey,
            $expectedActiveAssignmentId,
        );
    }

    private function lifecycle(): HousekeepingCleaningInspectionReadinessLifecycleService
    {
        return app(HousekeepingCleaningInspectionReadinessLifecycleService::class);
    }

    /** @return array<string, int> */
    private function durableCounts(): array
    {
        return [
            'tasks' => CleaningTask::count(),
            'assignments' => TaskAssignment::count(),
            'audits' => DB::table('audit_logs')->count(),
        ];
    }

    /** @param callable(): mixed $operation */
    private function expectBoundedConflict(callable $operation, array $before): void
    {
        try {
            $operation();
            $this->fail('Expected bounded assignment conflict.');
        } catch (DomainException $exception) {
            $this->assertContains($exception->getMessage(), [
                HousekeepingTaskDispatchAssignmentService::IDEMPOTENCY_CONFLICT,
                HousekeepingTaskDispatchAssignmentService::NOT_ELIGIBLE,
                HousekeepingTaskDispatchAssignmentService::STALE_ACTIVE_ASSIGNMENT,
                HousekeepingTaskDispatchAssignmentService::ATTENDANT_NOT_ELIGIBLE,
                HousekeepingTaskDispatchAssignmentService::DEPARTMENT_NOT_ELIGIBLE,
                HousekeepingTaskDispatchAssignmentService::SOURCE_CONFLICT,
            ]);
            $this->assertSame($before, $this->durableCounts());
        }
    }

    /** @param callable(): mixed $operation */
    private function expectBoundedDenial(callable $operation, array $before): void
    {
        try {
            $operation();
            $this->fail('Expected bounded assignment authorization denial.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(HousekeepingTaskDispatchAssignmentService::NOT_AUTHORIZED, $exception->getMessage());
            $this->assertSame($before, $this->durableCounts());
        }
    }
}
