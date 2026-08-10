<?php

namespace Tests\Postgres\Operations\Housekeeping;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\HousekeepingRoomReadinessTransitionTypeEnum;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Events\CleaningTaskCompleted;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\HousekeepingRoomReadinessTransition;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Services\HousekeepingCleaningInspectionReadinessLifecycleService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Modules\Operations\Housekeeping\Services\CleaningTaskService;
use Modules\Operations\Housekeeping\Services\InspectionService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingRoomReadinessData;
use Tests\PostgresTestCase;

class HousekeepingCleaningInspectionReadinessIntegrationTest extends PostgresTestCase
{
    use RefreshDatabase;
    use CreatesHousekeepingRoomReadinessData;

    private Department $assignmentDepartment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHousekeepingRoomReadinessFixture();
        $this->assignmentDepartment = Department::create([
            'property_id' => $this->property->id,
            'name' => 'Package 13 Housekeeping',
            'code' => 'P13' . Str::upper(Str::random(5)),
            'is_active' => true,
        ]);
        $this->housekeepingActor->update(['department_id' => $this->assignmentDepartment->id]);

        foreach ([
            'housekeeping.task.view',
            'housekeeping.task.edit',
            'housekeeping.task.start',
            'housekeeping.task.complete',
            'housekeeping.inspection.view',
            'housekeeping.inspection.create',
            'housekeeping.inspection.conduct',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->housekeepingActor->givePermissionTo([
            'housekeeping.task.view',
            'housekeeping.task.edit',
            'housekeeping.task.start',
            'housekeeping.task.complete',
        ]);
        $this->housekeepingInspector->givePermissionTo([
            'housekeeping.inspection.view',
            'housekeeping.inspection.create',
            'housekeeping.inspection.conduct',
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
        ]);
    }

    public function test_assigned_attendant_start_creates_exactly_one_source_bound_transition(): void
    {
        [$room, $task] = $this->assignedCheckoutTask('P13-101');

        $started = $this->lifecycle()->changeCleaningTaskStatus(
            $this->housekeepingActor,
            $task->id,
            TaskStatusEnum::InProgress,
        );
        $replay = $this->lifecycle()->changeCleaningTaskStatus(
            $this->housekeepingActor,
            $task->id,
            TaskStatusEnum::InProgress,
        );

        $transition = HousekeepingRoomReadinessTransition::where('idempotency_key', 'hk-task-start:' . $task->id)->firstOrFail();
        $this->assertSame($started->id, $replay->id);
        $this->assertSame(TaskStatusEnum::InProgress, $started->status);
        $this->assertNotNull($started->started_at);
        $this->assertSame('cleaning', $room->fresh()->readiness_state);
        $this->assertSame(HousekeepingRoomReadinessTransitionTypeEnum::StartCleaning, $transition->transition_type);
        $this->assertSame(CleaningTask::class, $transition->source_type);
        $this->assertSame($task->id, $transition->source_id);
        $this->assertSame(1, HousekeepingRoomReadinessTransition::where('idempotency_key', 'hk-task-start:' . $task->id)->count());
    }

    public function test_unassigned_or_readiness_unauthorized_actor_creates_zero_changes(): void
    {
        [$room, $task] = $this->assignedCheckoutTask('P13-102');
        $before = $this->lifecycleCounts();

        try {
            $this->lifecycle()->changeCleaningTaskStatus(
                $this->housekeepingInspector,
                $task->id,
                TaskStatusEnum::InProgress,
            );
            $this->fail('Expected unauthorized start rejection.');
        } catch (DomainException|HttpException) {
            $this->assertSame($before, $this->lifecycleCounts());
            $this->assertSame(TaskStatusEnum::Assigned, $task->fresh()->status);
            $this->assertSame('waiting_cleaning', $room->fresh()->readiness_state);
        }
    }

    public function test_completion_atomically_creates_submit_transition_and_one_pending_inspection(): void
    {
        [$room, $task] = $this->startedCheckoutTask('P13-103');

        $completed = $this->lifecycle()->changeCleaningTaskStatus(
            $this->housekeepingActor,
            $task->id,
            TaskStatusEnum::Completed,
            'Room fully cleaned and checked.',
        );

        $inspection = RoomInspection::where('cleaning_task_id', $task->id)->firstOrFail();
        $transition = HousekeepingRoomReadinessTransition::where('idempotency_key', 'hk-task-complete:' . $task->id)->firstOrFail();
        $this->assertSame(TaskStatusEnum::Completed, $completed->status);
        $this->assertSame($this->housekeepingActor->id, $completed->completed_by);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame(InspectionStatusEnum::Pending, $inspection->status);
        $this->assertSame('post_cleaning', $inspection->inspection_type->value);
        $this->assertSame(HousekeepingRoomReadinessTransitionTypeEnum::SubmitInspection, $transition->transition_type);
        $this->assertSame('clean', $room->fresh()->cleanliness_status->value);
        $this->assertSame('waiting_inspection', $room->fresh()->readiness_state);
    }

    public function test_completion_replay_returns_same_task_transition_and_inspection_identities(): void
    {
        [, $task] = $this->startedCheckoutTask('P13-104');
        $first = $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $task->id, TaskStatusEnum::Completed, 'Exact replay note.');
        $transitionId = HousekeepingRoomReadinessTransition::where('idempotency_key', 'hk-task-complete:' . $task->id)->value('id');
        $inspectionId = RoomInspection::where('cleaning_task_id', $task->id)->value('id');

        $second = $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $task->id, TaskStatusEnum::Completed, 'Exact replay note.');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($transitionId, HousekeepingRoomReadinessTransition::where('idempotency_key', 'hk-task-complete:' . $task->id)->value('id'));
        $this->assertSame($inspectionId, RoomInspection::where('cleaning_task_id', $task->id)->value('id'));
        $this->assertSame(1, RoomInspection::where('cleaning_task_id', $task->id)->count());
    }

    public function test_completion_failure_rolls_back_task_room_transition_and_inspection(): void
    {
        [$room, $task] = $this->startedCheckoutTask('P13-105');
        Event::listen(CleaningTaskCompleted::class, static function (): void {
            throw new DomainException('Injected completion rollback proof.');
        });

        try {
            $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $task->id, TaskStatusEnum::Completed, 'Rollback proof note.');
            $this->fail('Expected injected rollback.');
        } catch (DomainException $exception) {
            $this->assertSame('Injected completion rollback proof.', $exception->getMessage());
        }

        $this->assertSame(TaskStatusEnum::InProgress, $task->fresh()->status);
        $this->assertSame('cleaning', $room->fresh()->readiness_state);
        $this->assertSame(0, RoomInspection::where('cleaning_task_id', $task->id)->count());
        $this->assertSame(0, HousekeepingRoomReadinessTransition::where('idempotency_key', 'hk-task-complete:' . $task->id)->count());
    }

    public function test_conduct_changes_only_inspection_lifecycle_state_and_replays_exactly(): void
    {
        [$room, $task, $inspection] = $this->completedInspection('P13-106');
        $beforeRoom = $room->fresh()->getAttributes();
        $beforeTransitions = HousekeepingRoomReadinessTransition::count();

        $first = $this->lifecycle()->conductInspection($this->housekeepingInspector, $inspection->id);
        $second = $this->lifecycle()->conductInspection($this->housekeepingInspector, $inspection->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(InspectionStatusEnum::InProgress, $second->status);
        $this->assertSame($this->housekeepingInspector->id, $second->supervisor_id);
        $this->assertSame($beforeRoom, $room->fresh()->getAttributes());
        $this->assertSame($beforeTransitions, HousekeepingRoomReadinessTransition::count());
        $this->assertSame(TaskStatusEnum::Completed, $task->fresh()->status);
    }

    public function test_pass_without_valid_confirmation_creates_zero_decision_changes(): void
    {
        [$room, $task, $inspection] = $this->inProgressInspection('P13-107');

        $this->expectException(DomainException::class);
        try {
            $this->lifecycle()->passInspection($this->housekeepingInspector, $inspection->id, 'Release after quality approval.');
        } finally {
            $this->assertSame(InspectionStatusEnum::InProgress, $inspection->fresh()->status);
            $this->assertNull($task->fresh()->verified_at);
            $this->assertSame('waiting_inspection', $room->fresh()->readiness_state);
            $this->assertSame(0, HousekeepingRoomReadinessTransition::where('idempotency_key', 'hk-inspection-pass:' . $inspection->id)->count());
        }
    }

    public function test_valid_confirmation_atomically_passes_verifies_and_releases_room(): void
    {
        [$room, $task, $inspection] = $this->inProgressInspection('P13-108');
        $reason = 'Supervisor confirms all release standards are satisfied.';
        $context = $this->lifecycle()->confirmInspectionPass($this->housekeepingInspector, $inspection->id, $reason, 'password');

        $passed = $this->lifecycle()->passInspection($this->housekeepingInspector, $inspection->id, $reason);
        $transition = HousekeepingRoomReadinessTransition::where('idempotency_key', 'hk-inspection-pass:' . $inspection->id)->firstOrFail();

        $this->assertSame($room->room_number, $context['room_number']);
        $this->assertSame(InspectionStatusEnum::Passed, $passed->status);
        $this->assertTrue($passed->is_passed);
        $this->assertNotNull($passed->inspected_at);
        $this->assertNotNull($task->fresh()->verified_at);
        $this->assertSame('inspected', $room->fresh()->cleanliness_status->value);
        $this->assertSame('ready_for_sale', $room->fresh()->readiness_state);
        $this->assertSame(HousekeepingRoomReadinessTransitionTypeEnum::ReleaseReady, $transition->transition_type);
        $this->assertSame(RoomInspection::class, $transition->source_type);
        $this->assertSame($inspection->id, $transition->source_id);
    }

    public function test_stale_confirmation_after_room_evidence_change_is_rejected_without_decision_changes(): void
    {
        [$room, $task, $inspection] = $this->inProgressInspection('P13-109');
        $reason = 'Release evidence must remain current.';
        $this->lifecycle()->confirmInspectionPass($this->housekeepingInspector, $inspection->id, $reason, 'password');
        DB::table('rooms')->where('id', $room->id)->update(['is_vip' => true]);

        try {
            $this->lifecycle()->passInspection($this->housekeepingInspector, $inspection->id, $reason);
            $this->fail('Expected stale confirmation rejection.');
        } catch (DomainException) {
            $this->assertSame(InspectionStatusEnum::InProgress, $inspection->fresh()->status);
            $this->assertNull($task->fresh()->verified_at);
            $this->assertSame('waiting_inspection', $room->fresh()->readiness_state);
        }
    }

    public function test_failure_creates_one_canonical_transition_and_one_source_bound_recleaning_task(): void
    {
        [$room, $task, $inspection] = $this->inProgressInspection('P13-110');
        $reason = 'Bathroom floor requires re-cleaning.';
        $failed = $this->lifecycle()->failInspection($this->housekeepingInspector, $inspection->id, $reason);
        $replay = $this->lifecycle()->failInspection($this->housekeepingInspector, $inspection->id, $reason);
        $transition = HousekeepingRoomReadinessTransition::where('idempotency_key', 'hk-inspection-fail:' . $inspection->id)->firstOrFail();
        $rework = CleaningTask::where('rework_source_inspection_id', $inspection->id)->firstOrFail();

        $this->assertSame($failed->id, $replay->id);
        $this->assertSame(InspectionStatusEnum::Failed, $failed->status);
        $this->assertSame(HousekeepingRoomReadinessTransitionTypeEnum::InspectionFailed, $transition->transition_type);
        $this->assertSame($inspection->id, $transition->source_id);
        $this->assertSame($task->id, $rework->source_cleaning_task_id);
        $this->assertSame(TaskStatusEnum::Pending, $rework->status);
        $this->assertSame('waiting_cleaning', $room->fresh()->readiness_state);
        $this->assertSame('dirty', $room->fresh()->cleanliness_status->value);
        $this->assertSame(1, CleaningTask::where('rework_source_inspection_id', $inspection->id)->count());
    }

    public function test_opposite_terminal_decision_is_rejected(): void
    {
        [, , $inspection] = $this->inProgressInspection('P13-111');
        $this->lifecycle()->failInspection($this->housekeepingInspector, $inspection->id, 'Source-bound failure reason.');

        $this->expectException(DomainException::class);
        $this->lifecycle()->passInspection($this->housekeepingInspector, $inspection->id, 'Conflicting release decision.');
    }

    public function test_terminal_inspection_and_completed_task_evidence_are_application_immutable(): void
    {
        [, $task, $inspection] = $this->inProgressInspection('P13-112');
        $this->lifecycle()->failInspection($this->housekeepingInspector, $inspection->id, 'Immutable failure evidence.');

        try {
            $inspection->fresh()->update(['remarks' => 'Overwrite attempt']);
            $this->fail('Expected terminal Inspection update rejection.');
        } catch (DomainException) {
            $this->assertSame('Immutable failure evidence.', $inspection->fresh()->remarks);
        }

        try {
            $task->fresh()->update(['completed_at' => now()->addHour()]);
            $this->fail('Expected completed task rewrite rejection.');
        } catch (DomainException) {
            $this->assertNotNull($task->fresh()->completed_at);
        }

        $this->expectException(DomainException::class);
        $inspection->fresh()->delete();
    }

    public function test_cross_property_and_client_owned_lifecycle_authority_are_non_mutating(): void
    {
        [$room, $task] = $this->assignedCheckoutTask('P13-113');
        $this->actingAs($this->housekeepingActor)
            ->postJson('/operations/cleaning-tasks/' . $task->id . '/status', [
                'status' => 'in_progress',
                'property_id' => $this->otherProperty->id,
                'room_id' => $this->hkDirtyRoom($this->otherProperty, 'P13-X'),
            ], ['X-Property-ID' => $this->property->id])
            ->assertUnprocessable()
            ->assertJsonMissing(['source_hash']);

        $this->assertSame(TaskStatusEnum::Assigned, $task->fresh()->status);
        $this->assertSame('waiting_cleaning', $room->fresh()->readiness_state);
        $this->assertSame(0, HousekeepingRoomReadinessTransition::where('source_id', $task->id)->count());
    }

    public function test_lifecycle_json_response_contains_no_pii_secret_hash_or_raw_failure_detail(): void
    {
        [, $task] = $this->assignedCheckoutTask('P13-114');
        $response = $this->actingAs($this->housekeepingActor)
            ->postJson('/operations/cleaning-tasks/' . $task->id . '/status', ['status' => 'in_progress'], [
                'X-Property-ID' => $this->property->id,
            ])
            ->assertOk();

        $serialized = strtolower($response->getContent());
        foreach (['guest_name', 'guest_email', 'password', 'token', 'confirmation_hash', 'source_hash', 'sqlstate', 'exception', 'stack trace'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_readiness_permission_without_resource_policy_is_uniformly_denied_before_any_mutation(): void
    {
        $readinessOnly = $this->hkUser('Readiness Only', 'p13-readiness-only@example.test');
        $this->hkAttachProperty($readinessOnly, $this->property);
        $readinessOnly->givePermissionTo([
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
            HousekeepingRoomReadinessTransitionService::SUBMIT_INSPECTION_PERMISSION,
            HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
        ]);

        [, $assigned] = $this->assignedCheckoutTask('P13-AUTH-1');
        $cancelTask = CleaningTask::create([
            'property_id' => $this->property->id, 'room_id' => $assigned->room_id,
            'task_type' => 'checkout_cleaning', 'status' => 'pending', 'priority' => 'normal',
        ]);
        $routine = CleaningTask::create([
            'property_id' => $this->property->id, 'room_id' => $assigned->room_id,
            'task_type' => 'stayover_cleaning', 'status' => 'pending', 'priority' => 'normal',
        ]);
        [, , $inspection] = $this->inProgressInspection('P13-AUTH-2');
        $before = $this->lifecycleCounts();

        $operations = [
            fn () => $this->lifecycle()->changeCleaningTaskStatus($readinessOnly, $assigned->id, TaskStatusEnum::InProgress),
            fn () => $this->lifecycle()->changeCleaningTaskStatus($readinessOnly, $cancelTask->id, TaskStatusEnum::Cancelled),
            fn () => $this->lifecycle()->changeCleaningTaskStatus($readinessOnly, $routine->id, TaskStatusEnum::Cancelled),
            fn () => $this->lifecycle()->inspectionPassContext($readinessOnly, $inspection->id),
            fn () => $this->lifecycle()->confirmInspectionPass($readinessOnly, $inspection->id, 'Unauthorized release.', 'password'),
            fn () => $this->lifecycle()->passInspection($readinessOnly, $inspection->id, 'Unauthorized release.'),
            fn () => $this->lifecycle()->failInspection($readinessOnly, $inspection->id, 'Unauthorized failure.'),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Expected canonical authorization denial.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
                $this->assertSame('HOUSEKEEPING_LIFECYCLE_NOT_AUTHORIZED', $exception->getMessage());
            }
        }
        $this->assertSame($before, $this->lifecycleCounts());
    }

    public function test_resource_policy_without_required_readiness_permission_cannot_start_or_complete(): void
    {
        $policyOnly = $this->hkUser('Policy Only', 'p13-policy-only@example.test');
        $this->hkAttachProperty($policyOnly, $this->property);
        $policyOnly->givePermissionTo(['housekeeping.task.edit', 'housekeeping.task.start', 'housekeeping.task.complete']);

        [, $assigned] = $this->assignedCheckoutTask('P13-AUTH-3', $policyOnly);
        [$room, $inProgress] = $this->assignedCheckoutTask('P13-AUTH-4', $policyOnly);
        $inProgress->update(['status' => TaskStatusEnum::InProgress, 'started_at' => now()]);
        DB::table('rooms')->where('id', $room->id)->update(['readiness_state' => 'cleaning']);
        $before = $this->lifecycleCounts();

        foreach ([
            fn () => $this->lifecycle()->changeCleaningTaskStatus($policyOnly, $assigned->id, TaskStatusEnum::InProgress),
            fn () => $this->lifecycle()->changeCleaningTaskStatus($policyOnly, $inProgress->id, TaskStatusEnum::Completed, 'Unauthorized completion.'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected readiness permission denial.');
            } catch (HttpException $exception) {
                $this->assertSame('HOUSEKEEPING_LIFECYCLE_NOT_AUTHORIZED', $exception->getMessage());
            }
        }

        $this->assertSame($before, $this->lifecycleCounts());
        $this->assertSame('cleaning', $room->fresh()->readiness_state);
    }

    public function test_unknown_and_cross_property_identifiers_have_the_same_non_disclosing_denial(): void
    {
        $otherRoom = $this->hkDirtyRoom($this->otherProperty, 'P13-AUTH-X');
        $otherTask = CleaningTask::withoutGlobalScopes()->create([
            'property_id' => $this->otherProperty->id, 'room_id' => $otherRoom,
            'task_type' => 'checkout_cleaning', 'status' => 'pending', 'priority' => 'normal',
        ]);
        $messages = [];

        foreach ([$otherTask->id, '01HZZZZZZZZZZZZZZZZZZZZZZZ'] as $id) {
            try {
                $this->lifecycle()->changeCleaningTaskStatus($this->housekeepingActor, $id, TaskStatusEnum::Cancelled);
                $this->fail('Expected identifier denial.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
                $messages[] = $exception->getMessage();
            }
        }

        $this->assertSame(['HOUSEKEEPING_LIFECYCLE_NOT_AUTHORIZED', 'HOUSEKEEPING_LIFECYCLE_NOT_AUTHORIZED'], $messages);
        $this->assertSame(TaskStatusEnum::Pending, $otherTask->fresh()->status);
    }

    public function test_compatibility_actor_id_cannot_impersonate_another_authenticated_user(): void
    {
        [, $task] = $this->assignedCheckoutTask('P13-AUTH-5');
        [, , $inspection] = $this->inProgressInspection('P13-AUTH-6');
        $before = $this->lifecycleCounts();

        $this->actingAs($this->housekeepingActor);
        try {
            app(CleaningTaskService::class)->changeStatus($task->id, TaskStatusEnum::InProgress, $this->housekeepingInspector->id);
            $this->fail('Expected Cleaning Task impersonation denial.');
        } catch (HttpException $exception) {
            $this->assertSame('HOUSEKEEPING_LIFECYCLE_NOT_AUTHORIZED', $exception->getMessage());
        }

        $this->actingAs($this->housekeepingInspector);
        try {
            app(InspectionService::class)->fail($inspection->id, 'Impersonation attempt.', null, $this->housekeepingActor->id);
            $this->fail('Expected Inspection impersonation denial.');
        } catch (HttpException $exception) {
            $this->assertSame('HOUSEKEEPING_LIFECYCLE_NOT_AUTHORIZED', $exception->getMessage());
        }

        $this->assertSame($before, $this->lifecycleCounts());
    }

    public function test_terminal_replay_requires_exact_severity_and_creates_zero_new_facts_on_conflict(): void
    {
        [, , $passedInspection] = $this->inProgressInspection('P13-REPLAY-1');
        $passReason = 'Exact severity pass replay.';
        $this->lifecycle()->confirmInspectionPass($this->housekeepingInspector, $passedInspection->id, $passReason, 'password');
        $this->lifecycle()->passInspection($this->housekeepingInspector, $passedInspection->id, $passReason, InspectionSeverityEnum::Minor);
        $passBefore = $this->lifecycleCounts();

        try {
            $this->lifecycle()->passInspection($this->housekeepingInspector, $passedInspection->id, $passReason, null);
            $this->fail('Expected conflicting pass severity replay.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('replay conflicts', $exception->getMessage());
        }
        $this->assertSame($passBefore, $this->lifecycleCounts());

        [, , $failedInspection] = $this->inProgressInspection('P13-REPLAY-2');
        $failReason = 'Exact severity failure replay.';
        $this->lifecycle()->failInspection($this->housekeepingInspector, $failedInspection->id, $failReason, InspectionSeverityEnum::Major);
        $failBefore = $this->lifecycleCounts();

        try {
            $this->lifecycle()->failInspection($this->housekeepingInspector, $failedInspection->id, $failReason, null);
            $this->fail('Expected conflicting failure severity replay.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('replay conflicts', $exception->getMessage());
        }
        $this->assertSame($failBefore, $this->lifecycleCounts());
    }

    public function test_http_pass_replay_recovers_committed_result_without_new_confirmation_or_facts(): void
    {
        [, , $inspection] = $this->inProgressInspection('P13-HTTP-REPLAY');
        $reason = 'Recover committed release response.';
        $headers = ['X-Property-ID' => $this->property->id];

        $this->actingAs($this->housekeepingInspector)
            ->postJson('/operations/inspections/' . $inspection->id . '/pass-confirmation', [
                'release_reason' => $reason,
                'password' => 'password',
            ], $headers)
            ->assertOk();
        $this->postJson('/operations/inspections/' . $inspection->id . '/pass', ['release_reason' => $reason], $headers)->assertOk();
        $before = $this->lifecycleCounts();
        $confirmationBefore = session('sensitive_action_confirmation');

        $this->postJson('/operations/inspections/' . $inspection->id . '/pass', ['release_reason' => $reason], $headers)
            ->assertOk()
            ->assertJsonPath('inspection.id', $inspection->id);

        $this->assertSame($before, $this->lifecycleCounts());
        $this->assertSame($confirmationBefore, session('sensitive_action_confirmation'));
    }

    /** @return array{0: Room, 1: CleaningTask} */
    private function assignedCheckoutTask(string $roomNumber, ?User $assignee = null): array
    {
        $assignee ??= $this->housekeepingActor;
        $assignee->update(['department_id' => $this->assignmentDepartment->id]);
        $room = Room::findOrFail($this->hkDirtyRoom($this->property, $roomNumber));
        $task = CleaningTask::create([
            'property_id' => $this->property->id,
            'room_id' => $room->id,
            'task_code' => 'TASK-' . $roomNumber,
            'title' => 'Checkout clean ' . $roomNumber,
            'task_type' => 'checkout_cleaning',
            'status' => TaskStatusEnum::Assigned,
            'priority' => 'normal',
        ]);
        TaskAssignment::create([
            'property_id' => $this->property->id,
            'cleaning_task_id' => $task->id,
            'user_id' => $assignee->id,
            'attendant_id' => $assignee->id,
            'department_id' => $this->assignmentDepartment->id,
            'status' => 'active',
            'assigned_at' => now(),
            'assigned_by' => $assignee->id,
            'assignment_action' => 'initial',
            'idempotency_key' => 'p13-fixture-' . Str::uuid(),
            'source_hash' => hash('sha256', 'p13-fixture-' . $task->id . '-' . $assignee->id),
            'evidence_version' => 'housekeeping-assignment-v1',
        ]);

        return [$room, $task];
    }

    /** @return array{0: Room, 1: CleaningTask} */
    private function startedCheckoutTask(string $roomNumber, ?User $assignee = null): array
    {
        $assignee ??= $this->housekeepingActor;
        [$room, $task] = $this->assignedCheckoutTask($roomNumber, $assignee);
        $this->lifecycle()->changeCleaningTaskStatus($assignee, $task->id, TaskStatusEnum::InProgress);

        return [$room->fresh(), $task->fresh()];
    }

    /** @return array{0: Room, 1: CleaningTask, 2: RoomInspection} */
    private function completedInspection(string $roomNumber): array
    {
        [$room, $task] = $this->startedCheckoutTask($roomNumber);
        $task = $this->lifecycle()->changeCleaningTaskStatus(
            $this->housekeepingActor,
            $task->id,
            TaskStatusEnum::Completed,
            'Completed for inspection.',
        );
        $inspection = RoomInspection::where('cleaning_task_id', $task->id)->firstOrFail();

        return [$room->fresh(), $task->fresh(), $inspection];
    }

    /** @return array{0: Room, 1: CleaningTask, 2: RoomInspection} */
    private function inProgressInspection(string $roomNumber): array
    {
        [$room, $task, $inspection] = $this->completedInspection($roomNumber);
        $inspection = $this->lifecycle()->conductInspection($this->housekeepingInspector, $inspection->id);

        return [$room->fresh(), $task->fresh(), $inspection];
    }

    private function lifecycle(): HousekeepingCleaningInspectionReadinessLifecycleService
    {
        return app(HousekeepingCleaningInspectionReadinessLifecycleService::class);
    }

    /** @return array<string, int> */
    private function lifecycleCounts(): array
    {
        return [
            'transitions' => HousekeepingRoomReadinessTransition::count(),
            'inspections' => RoomInspection::count(),
            'tasks' => CleaningTask::count(),
        ];
    }
}
