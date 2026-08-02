<?php

namespace Modules\Operations\Housekeeping\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Enums\HousekeepingRoomReadinessTransitionTypeEnum;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Events\CleaningTaskCancelled;
use Modules\Operations\Housekeeping\Events\CleaningTaskCompleted;
use Modules\Operations\Housekeeping\Events\CleaningTaskStarted;
use Modules\Operations\Housekeeping\Events\InspectionCompleted;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\HousekeepingRoomReadinessTransition;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Canonical Package 13 orchestration boundary.
 *
 * Lock order for every integrated lifecycle mutation is:
 * Room -> CleaningTask -> RoomInspection -> TaskAssignment.
 * The Room aggregate is always acquired first, matching the readiness transition
 * authority, and every operation revalidates all source relationships under lock.
 */
class HousekeepingCleaningInspectionReadinessLifecycleService
{
    private const TASK_START_KEY = 'hk-task-start:';
    private const TASK_COMPLETE_KEY = 'hk-task-complete:';
    private const INSPECTION_FAIL_KEY = 'hk-inspection-fail:';
    private const INSPECTION_PASS_KEY = 'hk-inspection-pass:';
    private const AUTHORIZATION_DENIED = 'HOUSEKEEPING_LIFECYCLE_NOT_AUTHORIZED';

    public function __construct(
        private readonly HousekeepingRoomReadinessTransitionService $readiness,
        private readonly SensitiveActionConfirmationService $confirmation,
        private readonly CurrentPropertyService $currentProperty,
    ) {}

    public function changeCleaningTaskStatus(
        User $actor,
        string $taskId,
        TaskStatusEnum $target,
        ?string $notes = null,
    ): CleaningTask {
        $propertyId = $this->activePropertyId();
        $preview = $this->scopedTask($propertyId, $taskId);
        $this->authorizeTask($actor, $preview, match ($target) {
            TaskStatusEnum::InProgress => HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
            TaskStatusEnum::Completed => HousekeepingRoomReadinessTransitionService::SUBMIT_INSPECTION_PERMISSION,
            default => null,
        });
        $taskType = $preview->task_type instanceof \BackedEnum
            ? $preview->task_type->value
            : (string) $preview->task_type;

        if ($taskType !== 'checkout_cleaning') {
            return $this->changeNonCheckoutTaskStatus($actor, $propertyId, $preview, $target, $notes);
        }

        return match ($target) {
            TaskStatusEnum::InProgress => $this->startCheckoutCleaning($actor, $propertyId, $preview),
            TaskStatusEnum::Completed => $this->completeCheckoutCleaning($actor, $propertyId, $preview, $notes),
            TaskStatusEnum::Cancelled => $this->cancelCheckoutCleaning($actor, $propertyId, $preview),
            default => throw new DomainException('This Cleaning Task lifecycle change must use its canonical operational action.'),
        };
    }

    public function conductInspection(User $actor, string $inspectionId): RoomInspection
    {
        $propertyId = $this->activePropertyId();
        $preview = $this->scopedInspection($propertyId, $inspectionId);
        $this->authorizeInspection($actor, $preview);

        return DB::transaction(function () use ($actor, $propertyId, $preview, $inspectionId) {
            $room = $this->lockRoom($propertyId, (string) $preview->room_id);
            $inspection = $this->lockInspection($propertyId, $inspectionId);
            $this->authorizeInspection($actor, $inspection);
            $task = $this->lockInspectionTask($propertyId, $inspection, $room);
            $this->assertInspectionSource($inspection, $task, $room, $propertyId);

            if ($inspection->status === InspectionStatusEnum::InProgress) {
                if ($inspection->supervisor_id !== $actor->id) {
                    throw new DomainException('Inspection conduct replay conflicts with the recorded supervisor.');
                }

                return $inspection->fresh();
            }

            if ($inspection->status !== InspectionStatusEnum::Pending) {
                throw new DomainException('Only a pending Room Inspection can be conducted.');
            }

            $inspection->update([
                'status' => InspectionStatusEnum::InProgress,
                'supervisor_id' => $actor->id,
            ]);

            return $inspection->fresh();
        });
    }

    /**
     * @return array<string, string>
     */
    public function inspectionPassContext(User $actor, string $inspectionId): array
    {
        $propertyId = $this->activePropertyId();
        $inspection = $this->scopedInspection($propertyId, $inspectionId);
        $this->authorizeInspection($actor, $inspection, HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION);
        $room = Room::withoutGlobalScopes()
            ->whereKey($inspection->room_id)
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->first();
        $task = CleaningTask::withoutGlobalScopes()
            ->whereKey($inspection->cleaning_task_id)
            ->where('property_id', $propertyId)
            ->first();

        if (! $room || ! $task) {
            throw new DomainException('Room Inspection source evidence is unavailable.');
        }

        $this->assertInspectionSource($inspection, $task, $room, $propertyId);
        if ($inspection->status !== InspectionStatusEnum::InProgress) {
            throw new DomainException('Only an in-progress Room Inspection can be passed.');
        }

        return [
            'room_number' => (string) $room->room_number,
            'inspection_status' => InspectionStatusEnum::InProgress->value,
            'target_readiness' => $this->readiness->targetReadinessFor($room),
            'cleaning_task_code' => (string) ($task->task_code ?: $task->id),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function confirmInspectionPass(
        User $actor,
        string $inspectionId,
        string $releaseReason,
        string $password,
    ): array {
        $propertyId = $this->activePropertyId();
        $preview = $this->scopedInspection($propertyId, $inspectionId);
        $this->authorizeInspection($actor, $preview, HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION);
        $releaseReason = $this->requiredText($releaseReason, 'A release reason is required.');

        return DB::transaction(function () use ($actor, $propertyId, $preview, $inspectionId, $releaseReason, $password) {
            $room = $this->lockRoom($propertyId, (string) $preview->room_id);
            $inspection = $this->lockInspection($propertyId, $inspectionId);
            $this->authorizeInspection($actor, $inspection, HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION);
            $task = $this->lockInspectionTask($propertyId, $inspection, $room);
            $this->assertInspectionSource($inspection, $task, $room, $propertyId);

            if ($inspection->status !== InspectionStatusEnum::InProgress) {
                throw new DomainException('Only an in-progress Room Inspection can be passed.');
            }

            $currentReadiness = (string) $room->readiness_state;
            if ($currentReadiness !== 'waiting_inspection') {
                throw new DomainException('Room readiness evidence is not eligible for release.');
            }

            $targetReadiness = $this->readiness->targetReadinessFor($room);
            $context = self::INSPECTION_PASS_KEY . $inspection->id;
            $hash = $this->readiness->releaseEvidenceHash(
                $room,
                $currentReadiness,
                $targetReadiness,
                $releaseReason,
                $context,
                $inspection->id,
                $task->id,
            );

            $this->confirmation->confirm(
                $actor,
                HousekeepingRoomReadinessTransitionService::RELEASE_INTENT,
                $password,
                session('active_company_id'),
                $propertyId,
                $hash,
            );

            return [
                'room_number' => (string) $room->room_number,
                'inspection_status' => InspectionStatusEnum::InProgress->value,
                'target_readiness' => $targetReadiness,
                'cleaning_task_code' => (string) ($task->task_code ?: $task->id),
            ];
        });
    }

    public function passInspection(
        User $actor,
        string $inspectionId,
        string $releaseReason,
        ?InspectionSeverityEnum $severity = null,
    ): RoomInspection {
        $propertyId = $this->activePropertyId();
        $preview = $this->scopedInspection($propertyId, $inspectionId);
        $this->authorizeInspection($actor, $preview, HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION);
        $releaseReason = $this->requiredText($releaseReason, 'A release reason is required.');

        return DB::transaction(function () use ($actor, $propertyId, $preview, $inspectionId, $releaseReason, $severity) {
            $room = $this->lockRoom($propertyId, (string) $preview->room_id);
            $inspection = $this->lockInspection($propertyId, $inspectionId);
            $this->authorizeInspection($actor, $inspection, HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION);
            $task = $this->lockInspectionTask($propertyId, $inspection, $room);
            $this->assertInspectionSource($inspection, $task, $room, $propertyId);

            $context = self::INSPECTION_PASS_KEY . $inspection->id;
            if ($inspection->status === InspectionStatusEnum::Passed) {
                $this->assertCommittedInspectionReplay(
                    $propertyId,
                    $inspection,
                    $task,
                    $actor,
                    $context,
                    $releaseReason,
                    $severity,
                    $room,
                );

                return $inspection->fresh();
            }

            if ($inspection->status !== InspectionStatusEnum::InProgress) {
                throw new DomainException('Only an in-progress Room Inspection can be passed.');
            }

            $transition = $this->readiness->releaseReady(
                $actor,
                $room->id,
                $releaseReason,
                $context,
                RoomInspection::class,
                $inspection->id,
                $task->id,
            );

            $inspection->update([
                'status' => InspectionStatusEnum::Passed,
                'inspected_at' => now(),
                'remarks' => $releaseReason,
                'is_passed' => true,
                'inspection_severity' => $severity,
            ]);

            $task->update(['verified_at' => now()]);
            event(new InspectionCompleted($inspection->fresh()));

            if ($transition->source_id !== $inspection->id) {
                throw new DomainException('Room release transition source evidence is inconsistent.');
            }

            return $inspection->fresh();
        });
    }

    public function failInspection(
        User $actor,
        string $inspectionId,
        string $failureReason,
        ?InspectionSeverityEnum $severity = null,
    ): RoomInspection {
        $propertyId = $this->activePropertyId();
        $preview = $this->scopedInspection($propertyId, $inspectionId);
        $this->authorizeInspection($actor, $preview, HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION);
        $failureReason = $this->requiredText($failureReason, 'A failure reason is required.');

        return DB::transaction(function () use ($actor, $propertyId, $preview, $inspectionId, $failureReason, $severity) {
            $room = $this->lockRoom($propertyId, (string) $preview->room_id);
            $inspection = $this->lockInspection($propertyId, $inspectionId);
            $this->authorizeInspection($actor, $inspection, HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION);
            $task = $this->lockInspectionTask($propertyId, $inspection, $room);
            $this->assertInspectionSource($inspection, $task, $room, $propertyId);

            $context = self::INSPECTION_FAIL_KEY . $inspection->id;
            if ($inspection->status === InspectionStatusEnum::Failed) {
                $this->assertCommittedFailureReplay($propertyId, $inspection, $task, $actor, $context, $failureReason, $severity, $room);

                return $inspection->fresh();
            }

            if ($inspection->status !== InspectionStatusEnum::InProgress) {
                throw new DomainException('Only an in-progress Room Inspection can be failed.');
            }

            $transition = $this->readiness->inspectionFailed(
                $actor,
                $room->id,
                $failureReason,
                $context,
                RoomInspection::class,
                $inspection->id,
            );

            $inspection->update([
                'status' => InspectionStatusEnum::Failed,
                'inspected_at' => now(),
                'remarks' => $failureReason,
                'is_passed' => false,
                'inspection_severity' => $severity,
            ]);

            CleaningTask::create([
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'task_code' => 'RECLN-' . $inspection->id,
                'title' => 'Re-clean Room ' . $room->room_number,
                'task_type' => 'checkout_cleaning',
                'status' => TaskStatusEnum::Pending,
                'priority' => $room->is_vip ? 'rush' : 'normal',
                'credits' => $task->credits ?: 1,
                'sla_minutes_target' => $task->sla_minutes_target ?: 45,
                'notes' => 'Re-cleaning required after failed inspection.',
                'rework_source_inspection_id' => $inspection->id,
                'source_cleaning_task_id' => $task->id,
                'created_by' => $actor->id,
            ]);

            event(new InspectionCompleted($inspection->fresh()));

            if ($transition->source_id !== $inspection->id) {
                throw new DomainException('Inspection failure transition source evidence is inconsistent.');
            }

            return $inspection->fresh();
        });
    }

    private function startCheckoutCleaning(User $actor, string $propertyId, CleaningTask $preview): CleaningTask
    {
        return DB::transaction(function () use ($actor, $propertyId, $preview) {
            $room = $this->lockRoom($propertyId, (string) $preview->room_id);
            $task = $this->lockTask($propertyId, $preview->id);
            $this->authorizeTask($actor, $task, HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION);
            $this->assertTaskRoom($task, $room, $propertyId);
            $key = self::TASK_START_KEY . $task->id;

            if ($task->status === TaskStatusEnum::InProgress) {
                $transition = $this->readiness->startCleaning(
                    $actor,
                    $room->id,
                    $key,
                    CleaningTask::class,
                    $task->id,
                );
                if ($transition->created_by !== $actor->id || $task->started_at === null) {
                    throw new DomainException('Cleaning Task start replay conflicts with committed evidence.');
                }

                return $task->fresh();
            }

            if ($task->status !== TaskStatusEnum::Assigned) {
                throw new DomainException('Only an assigned checkout-cleaning task can be started.');
            }

            $this->lockActiveAssignment($task, $actor);
            $this->readiness->startCleaning($actor, $room->id, $key, CleaningTask::class, $task->id);

            $task->update([
                'status' => TaskStatusEnum::InProgress,
                'started_at' => now(),
            ]);
            event(new CleaningTaskStarted($task->fresh()));

            return $task->fresh();
        });
    }

    private function completeCheckoutCleaning(
        User $actor,
        string $propertyId,
        CleaningTask $preview,
        ?string $notes,
    ): CleaningTask {
        $notes = $this->requiredText($notes, 'Completion note is required to complete this task.');

        return DB::transaction(function () use ($actor, $propertyId, $preview, $notes) {
            $room = $this->lockRoom($propertyId, (string) $preview->room_id);
            $task = $this->lockTask($propertyId, $preview->id);
            $this->authorizeTask($actor, $task, HousekeepingRoomReadinessTransitionService::SUBMIT_INSPECTION_PERMISSION);
            $this->assertTaskRoom($task, $room, $propertyId);
            $key = self::TASK_COMPLETE_KEY . $task->id;

            if ($task->status === TaskStatusEnum::Completed) {
                $transition = $this->readiness->submitInspection(
                    $actor,
                    $room->id,
                    $key,
                    'Cleaning completed by assigned attendant',
                    CleaningTask::class,
                    $task->id,
                );
                $inspection = $this->postCleaningInspection($propertyId, $task, $room);
                if (
                    $transition->created_by !== $actor->id
                    || $task->completed_by !== $actor->id
                    || trim((string) $task->notes) !== $notes
                    || ! $inspection
                ) {
                    throw new DomainException('Cleaning Task completion replay conflicts with committed evidence.');
                }

                return $task->fresh();
            }

            if ($task->status !== TaskStatusEnum::InProgress) {
                throw new DomainException('Only an in-progress checkout-cleaning task can be completed.');
            }

            $this->lockActiveAssignment($task, $actor);
            $this->readiness->submitInspection(
                $actor,
                $room->id,
                $key,
                'Cleaning completed by assigned attendant',
                CleaningTask::class,
                $task->id,
            );

            $task->update([
                'status' => TaskStatusEnum::Completed,
                'completed_at' => now(),
                'completed_by' => $actor->id,
                'notes' => $notes,
            ]);

            RoomInspection::create([
                'property_id' => $propertyId,
                'room_id' => $room->id,
                'cleaning_task_id' => $task->id,
                'status' => InspectionStatusEnum::Pending,
                'inspection_type' => 'post_cleaning',
            ]);

            event(new CleaningTaskCompleted($task->fresh()));

            return $task->fresh();
        });
    }

    private function cancelCheckoutCleaning(User $actor, string $propertyId, CleaningTask $preview): CleaningTask
    {
        return DB::transaction(function () use ($actor, $propertyId, $preview) {
            $room = $this->lockRoom($propertyId, (string) $preview->room_id);
            $task = $this->lockTask($propertyId, $preview->id);
            $this->authorizeTask($actor, $task);
            $this->assertTaskRoom($task, $room, $propertyId);

            if (! in_array($task->status, [TaskStatusEnum::Pending, TaskStatusEnum::Assigned], true)) {
                throw new DomainException('An active or completed checkout-cleaning task cannot be cancelled through the generic status action.');
            }

            $task->update(['status' => TaskStatusEnum::Cancelled]);
            event(new CleaningTaskCancelled($task->fresh(), null));

            return $task->fresh();
        });
    }

    private function changeNonCheckoutTaskStatus(
        User $actor,
        string $propertyId,
        CleaningTask $preview,
        TaskStatusEnum $target,
        ?string $notes,
    ): CleaningTask {
        return DB::transaction(function () use ($actor, $propertyId, $preview, $target, $notes) {
            $task = $this->lockTask($propertyId, $preview->id);
            $this->authorizeTask($actor, $task, match ($target) {
                TaskStatusEnum::InProgress => HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
                TaskStatusEnum::Completed => HousekeepingRoomReadinessTransitionService::SUBMIT_INSPECTION_PERMISSION,
                default => null,
            });
            if (! $task->status->canTransitionTo($target)) {
                throw new DomainException('Invalid Cleaning Task status transition.');
            }

            if (in_array($target, [TaskStatusEnum::InProgress, TaskStatusEnum::Completed], true)) {
                $this->lockActiveAssignment($task, $actor);
            }

            $updates = ['status' => $target];
            if ($target === TaskStatusEnum::InProgress) {
                $updates['started_at'] = now();
            } elseif ($target === TaskStatusEnum::Completed) {
                $updates['notes'] = $this->requiredText($notes, 'Completion note is required to complete this task.');
                $updates['completed_at'] = now();
                $updates['completed_by'] = $actor->id;
            }
            $task->update($updates);

            match ($target) {
                TaskStatusEnum::InProgress => event(new CleaningTaskStarted($task->fresh())),
                TaskStatusEnum::Completed => event(new CleaningTaskCompleted($task->fresh())),
                TaskStatusEnum::Cancelled => event(new CleaningTaskCancelled($task->fresh(), null)),
                default => null,
            };

            return $task->fresh();
        });
    }

    private function activePropertyId(): string
    {
        $propertyId = $this->currentProperty->resolveOrFail();
        $query = Property::withoutGlobalScopes()
            ->whereKey($propertyId)
            ->where('is_active', true);
        $companyId = session('active_company_id');
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }
        if (! $query->exists()) {
            throw new AuthorizationException('Active property is required.');
        }

        setPermissionsTeamId($propertyId);

        return $propertyId;
    }

    private function scopedTask(string $propertyId, string $taskId): CleaningTask
    {
        $task = CleaningTask::withoutGlobalScopes()
            ->whereKey($taskId)
            ->where('property_id', $propertyId)
            ->first();
        if (! $task) {
            $this->deny();
        }

        return $task;
    }

    private function scopedInspection(string $propertyId, string $inspectionId): RoomInspection
    {
        $inspection = RoomInspection::withoutGlobalScopes()
            ->whereKey($inspectionId)
            ->where('property_id', $propertyId)
            ->first();
        if (! $inspection) {
            $this->deny();
        }

        return $inspection;
    }

    private function lockRoom(string $propertyId, string $roomId): Room
    {
        $room = Room::withoutGlobalScopes()
            ->whereKey($roomId)
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
        if (! $room) {
            $this->deny();
        }

        return $room;
    }

    private function lockTask(string $propertyId, string $taskId): CleaningTask
    {
        $task = CleaningTask::withoutGlobalScopes()
            ->whereKey($taskId)
            ->where('property_id', $propertyId)
            ->lockForUpdate()
            ->first();
        if (! $task) {
            $this->deny();
        }

        return $task;
    }

    private function lockInspection(string $propertyId, string $inspectionId): RoomInspection
    {
        $inspection = RoomInspection::withoutGlobalScopes()
            ->whereKey($inspectionId)
            ->where('property_id', $propertyId)
            ->lockForUpdate()
            ->first();
        if (! $inspection) {
            $this->deny();
        }

        return $inspection;
    }

    private function lockInspectionTask(string $propertyId, RoomInspection $inspection, Room $room): CleaningTask
    {
        if (! $inspection->cleaning_task_id) {
            throw new DomainException('Room Inspection is not linked to a Cleaning Task.');
        }

        $task = $this->lockTask($propertyId, $inspection->cleaning_task_id);
        if ($task->room_id !== $room->id) {
            throw new DomainException('Room Inspection Cleaning Task relationship is inconsistent.');
        }

        return $task;
    }

    private function lockActiveAssignment(CleaningTask $task, User $actor): TaskAssignment
    {
        $assignment = TaskAssignment::withoutGlobalScopes()
            ->where('cleaning_task_id', $task->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();
        if (! $assignment) {
            throw new DomainException('Only the active assigned room attendant can perform this Cleaning Task action.');
        }

        return $assignment;
    }

    private function assertTaskRoom(CleaningTask $task, Room $room, string $propertyId): void
    {
        if ($task->property_id !== $propertyId || $task->room_id !== $room->id || $room->property_id !== $propertyId) {
            throw new DomainException('Cleaning Task Room relationship is inconsistent.');
        }
    }

    private function assertInspectionSource(
        RoomInspection $inspection,
        CleaningTask $task,
        Room $room,
        string $propertyId,
    ): void {
        $taskType = $task->task_type instanceof \BackedEnum ? $task->task_type->value : (string) $task->task_type;
        $inspectionType = $inspection->inspection_type instanceof \BackedEnum
            ? $inspection->inspection_type->value
            : (string) $inspection->inspection_type;

        if (
            $inspection->property_id !== $propertyId
            || $task->property_id !== $propertyId
            || $room->property_id !== $propertyId
            || $inspection->room_id !== $room->id
            || $inspection->cleaning_task_id !== $task->id
            || $task->room_id !== $room->id
            || $taskType !== 'checkout_cleaning'
            || $inspectionType !== 'post_cleaning'
            || $task->status !== TaskStatusEnum::Completed
        ) {
            throw new DomainException('Room Inspection source relationships are inconsistent.');
        }
    }

    private function postCleaningInspection(string $propertyId, CleaningTask $task, Room $room): ?RoomInspection
    {
        return RoomInspection::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('room_id', $room->id)
            ->where('cleaning_task_id', $task->id)
            ->where('inspection_type', 'post_cleaning')
            ->lockForUpdate()
            ->first();
    }

    private function assertCommittedInspectionReplay(
        string $propertyId,
        RoomInspection $inspection,
        CleaningTask $task,
        User $actor,
        string $context,
        string $reason,
        ?InspectionSeverityEnum $severity,
        Room $room,
    ): void {
        $transition = HousekeepingRoomReadinessTransition::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('idempotency_key', $context)
            ->lockForUpdate()
            ->first();
        if (
            ! $transition
            || $inspection->status !== InspectionStatusEnum::Passed
            || $inspection->is_passed !== true
            || $inspection->remarks !== $reason
            || $this->enumValue($inspection->inspection_severity) !== $this->enumValue($severity)
            || $inspection->inspected_at === null
            || $transition->transition_type !== HousekeepingRoomReadinessTransitionTypeEnum::ReleaseReady
            || $transition->source_type !== RoomInspection::class
            || $transition->source_id !== $inspection->id
            || $transition->reason !== $reason
            || $transition->created_by !== $actor->id
            || $task->verified_at === null
            || $transition->room_id !== $room->id
            || $transition->to_status !== $this->readiness->targetReadinessFor($room)
            || $room->readiness_state !== $transition->to_status
            || $this->enumValue($room->cleanliness_status) !== 'inspected'
        ) {
            throw new DomainException('Inspection pass replay conflicts with committed evidence.');
        }
    }

    private function assertCommittedFailureReplay(
        string $propertyId,
        RoomInspection $inspection,
        CleaningTask $task,
        User $actor,
        string $context,
        string $reason,
        ?InspectionSeverityEnum $severity,
        Room $room,
    ): void {
        $transition = HousekeepingRoomReadinessTransition::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('idempotency_key', $context)
            ->lockForUpdate()
            ->first();
        $reworks = CleaningTask::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('rework_source_inspection_id', $inspection->id)
            ->lockForUpdate()
            ->get();
        $rework = $reworks->first();
        if (
            ! $transition
            || ! $rework
            || $reworks->count() !== 1
            || $inspection->status !== InspectionStatusEnum::Failed
            || $inspection->is_passed !== false
            || $inspection->remarks !== $reason
            || $this->enumValue($inspection->inspection_severity) !== $this->enumValue($severity)
            || $inspection->inspected_at === null
            || $transition->transition_type !== HousekeepingRoomReadinessTransitionTypeEnum::InspectionFailed
            || $transition->source_type !== RoomInspection::class
            || $transition->source_id !== $inspection->id
            || $transition->reason !== $reason
            || $transition->created_by !== $actor->id
            || $rework->source_cleaning_task_id !== $task->id
            || $rework->property_id !== $propertyId
            || $rework->room_id !== $room->id
            || $this->enumValue($rework->task_type) !== 'checkout_cleaning'
            || $transition->room_id !== $room->id
            || $transition->to_status !== 'waiting_cleaning'
            || $room->readiness_state !== 'waiting_cleaning'
            || $this->enumValue($room->cleanliness_status) !== 'dirty'
        ) {
            throw new DomainException('Inspection failure replay conflicts with committed evidence.');
        }
    }

    private function requiredText(?string $value, string $message): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new DomainException($message);
        }

        return $value;
    }

    private function authorizeTask(User $actor, CleaningTask $task, ?string $readinessPermission = null): void
    {
        if (! $actor->can('changeStatus', $task) || ($readinessPermission !== null && ! $actor->can($readinessPermission))) {
            $this->deny();
        }
    }

    private function authorizeInspection(User $actor, RoomInspection $inspection, ?string $readinessPermission = null): void
    {
        if (! $actor->can('conduct', $inspection) || ($readinessPermission !== null && ! $actor->can($readinessPermission))) {
            $this->deny();
        }
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return $value === null ? null : (string) $value;
    }

    private function deny(): never
    {
        throw new HttpException(403, self::AUTHORIZATION_DENIED);
    }
}
