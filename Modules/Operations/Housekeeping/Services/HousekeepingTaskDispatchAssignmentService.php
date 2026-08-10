<?php

namespace Modules\Operations\Housekeeping\Services;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\AssignmentStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Events\CleaningTaskAssigned;
use Modules\Operations\Housekeeping\Events\CleaningTaskReassigned;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\ValueObjects\HousekeepingTaskAssignmentResult;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The only production write authority for Housekeeping TaskAssignment evidence.
 *
 * Canonical lock order: Room -> CleaningTask -> active TaskAssignment -> target
 * User -> property_user membership -> Department -> idempotency evidence.
 */
class HousekeepingTaskDispatchAssignmentService
{
    public const ASSIGN_PERMISSION = 'housekeeping.task.assign';
    public const EVIDENCE_VERSION = 'housekeeping-assignment-v1';
    public const NOT_AUTHORIZED = 'HK_ASSIGNMENT_NOT_AUTHORIZED';
    public const NOT_ELIGIBLE = 'HK_ASSIGNMENT_NOT_ELIGIBLE';
    public const STALE_ACTIVE_ASSIGNMENT = 'HK_ASSIGNMENT_STALE_ACTIVE_ASSIGNMENT';
    public const ATTENDANT_NOT_ELIGIBLE = 'HK_ASSIGNMENT_ATTENDANT_NOT_ELIGIBLE';
    public const DEPARTMENT_NOT_ELIGIBLE = 'HK_ASSIGNMENT_DEPARTMENT_NOT_ELIGIBLE';
    public const IDEMPOTENCY_CONFLICT = 'HK_ASSIGNMENT_IDEMPOTENCY_CONFLICT';
    public const SOURCE_CONFLICT = 'HK_ASSIGNMENT_SOURCE_CONFLICT';

    public function __construct(private readonly CurrentPropertyService $currentProperty) {}

    public function assignOrReassign(
        User $actor,
        string $taskId,
        string $attendantId,
        string $departmentId,
        string $idempotencyKey,
        ?string $expectedActiveAssignmentId,
    ): HousekeepingTaskAssignmentResult {
        $propertyId = $this->activePropertyId();
        $preview = $this->scopedTask($propertyId, $taskId);
        $this->authorize($actor, $preview, $propertyId);
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $expectedActiveAssignmentId = $this->normalizeExpectedAssignmentId($expectedActiveAssignmentId);

        try {
            return DB::transaction(function () use (
                $actor,
                $propertyId,
                $preview,
                $attendantId,
                $departmentId,
                $idempotencyKey,
                $expectedActiveAssignmentId,
            ): HousekeepingTaskAssignmentResult {
                $room = $this->lockRoom($propertyId, (string) $preview->room_id);
                $task = $this->lockTask($propertyId, $preview->id);
                $active = $this->lockActiveAssignments($task);
                $this->authorize($actor, $task, $propertyId);
                $this->assertTaskRoom($task, $room, $propertyId);

                $target = $this->lockTargetUser($attendantId);
                $membership = $this->lockMembership($propertyId, $attendantId);
                $department = $this->lockDepartment($departmentId);
                $existing = $this->lockIdempotencyEvidence($propertyId, $idempotencyKey);

                if ($existing) {
                    $operation = (string) $existing->assignment_action;
                    $hash = $this->sourceHash(
                        $operation,
                        $propertyId,
                        $task->id,
                        $attendantId,
                        $departmentId,
                        $expectedActiveAssignmentId,
                    );

                    return $this->replayOrConflict($existing, $task, $target, $department, $operation, $hash);
                }

                $this->assertTargetEligible($target, $membership, $department, $propertyId, $departmentId);
                $operation = null;
                $previous = null;
                if (
                    $task->status === TaskStatusEnum::Pending
                    && $task->started_at === null
                    && $active->isEmpty()
                    && $expectedActiveAssignmentId === null
                ) {
                    $operation = 'initial';
                } elseif ($task->status === TaskStatusEnum::Assigned && $task->started_at === null) {
                    if (
                        $active->count() !== 1
                        || $expectedActiveAssignmentId === null
                        || $active->first()->id !== $expectedActiveAssignmentId
                    ) {
                        throw new DomainException(self::STALE_ACTIVE_ASSIGNMENT);
                    }
                    if ($active->first()->user_id === $target->id) {
                        throw new DomainException(self::NOT_ELIGIBLE);
                    }
                    $operation = 'reassignment';
                    $previous = $active->first();
                } else {
                    throw new DomainException(self::NOT_ELIGIBLE);
                }

                $hash = $this->sourceHash(
                    $operation,
                    $propertyId,
                    $task->id,
                    $attendantId,
                    $departmentId,
                    $expectedActiveAssignmentId,
                );

                if ($operation === 'initial') {
                    $task->update(['status' => TaskStatusEnum::Assigned]);
                } else {
                    $this->close($previous, AssignmentStatusEnum::Cancelled, $actor, 'reassigned');
                }

                $assignment = TaskAssignment::create([
                    'property_id' => $propertyId,
                    'cleaning_task_id' => $task->id,
                    'user_id' => $target->id,
                    'attendant_id' => $target->id,
                    'department_id' => $department->id,
                    'status' => AssignmentStatusEnum::Active,
                    'assigned_at' => now(),
                    'assigned_by' => $actor->id,
                    'assignment_action' => $operation,
                    'idempotency_key' => $idempotencyKey,
                    'source_hash' => $hash,
                    'evidence_version' => self::EVIDENCE_VERSION,
                    'previous_assignment_id' => $previous?->id,
                ]);

                $auditEvent = $operation === 'initial'
                    ? 'housekeeping_task_assigned'
                    : 'housekeeping_task_reassigned';
                $this->recordAudit($auditEvent, $actor, $assignment, [
                    'cleaning_task_id' => $task->id,
                    'assignment_id' => $assignment->id,
                    'previous_assignment_id' => $previous?->id,
                    'user_id' => $target->id,
                    'department_id' => $department->id,
                    'assignment_action' => $operation,
                    'task_status' => TaskStatusEnum::Assigned->value,
                    'idempotency_digest' => hash('sha256', $idempotencyKey),
                ]);

                if ($operation === 'initial') {
                    event(new CleaningTaskAssigned($task->fresh(), $assignment));
                } else {
                    event(new CleaningTaskReassigned($task->fresh(), $assignment, $previous->id));
                }

                return $this->result($assignment, $task->fresh(), $target, $department, false);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw new DomainException(self::SOURCE_CONFLICT);
            }
            throw $exception;
        }
    }

    public function completeForLifecycle(User $actor, CleaningTask $task): TaskAssignment
    {
        $active = $this->lockActiveAssignments($task);
        if ($active->count() !== 1 || $active->first()->user_id !== $actor->id) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }

        /** @var TaskAssignment $assignment */
        $assignment = $active->first();
        $this->close($assignment, AssignmentStatusEnum::Completed, $actor, 'Cleaning Task completed.');
        $this->recordAudit('housekeeping_task_assignment_completed', $actor, $assignment, [
            'cleaning_task_id' => $task->id,
            'assignment_id' => $assignment->id,
            'assignment_status' => AssignmentStatusEnum::Completed->value,
        ]);

        return $assignment->fresh();
    }

    public function cancelForLifecycle(User $actor, CleaningTask $task): ?TaskAssignment
    {
        $active = $this->lockActiveAssignments($task);
        if ($task->status === TaskStatusEnum::Pending && $active->isEmpty()) {
            return null;
        }
        if ($active->count() !== 1) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }

        /** @var TaskAssignment $assignment */
        $assignment = $active->first();
        $this->close($assignment, AssignmentStatusEnum::Cancelled, $actor, 'Cleaning Task cancelled.');
        $this->recordAudit('housekeeping_task_assignment_cancelled', $actor, $assignment, [
            'cleaning_task_id' => $task->id,
            'assignment_id' => $assignment->id,
            'assignment_status' => AssignmentStatusEnum::Cancelled->value,
        ]);

        return $assignment->fresh();
    }

    public function assertCompletedReplay(User $actor, CleaningTask $task): TaskAssignment
    {
        $assignment = TaskAssignment::withoutGlobalScopes()
            ->where('property_id', $task->property_id)
            ->where('cleaning_task_id', $task->id)
            ->where('status', AssignmentStatusEnum::Completed)
            ->whereNull('deleted_at')
            ->orderByDesc('assigned_at')
            ->lockForUpdate()
            ->first();
        if (
            ! $assignment
            || $assignment->user_id !== $actor->id
            || $assignment->closed_by !== $actor->id
            || $assignment->closed_at === null
            || $assignment->completed_at === null
        ) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }

        return $assignment;
    }

    private function activePropertyId(): string
    {
        $propertyId = $this->currentProperty->resolveOrFail();
        $property = Property::withoutGlobalScopes()
            ->join('companies as company', 'company.id', '=', 'properties.company_id')
            ->whereKey($propertyId)
            ->where('properties.is_active', true)
            ->whereNull('properties.deleted_at')
            ->where('company.is_active', true)
            ->whereNull('company.deleted_at')
            ->select('properties.*')
            ->first();
        $companyId = session('active_company_id');
        if (! $property || ($companyId !== null && $property->company_id !== $companyId)) {
            $this->deny();
        }
        setPermissionsTeamId($propertyId);

        return $propertyId;
    }

    private function scopedTask(string $propertyId, string $taskId): CleaningTask
    {
        $task = CleaningTask::withoutGlobalScopes()
            ->whereKey($taskId)
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->first();
        if (! $task) {
            $this->deny();
        }

        return $task;
    }

    private function authorize(User $actor, CleaningTask $task, string $propertyId): void
    {
        try {
            $hasPermission = $actor->hasPermissionTo(self::ASSIGN_PERMISSION);
        } catch (PermissionDoesNotExist) {
            $hasPermission = false;
        }
        if (! $actor->is_active || $actor->deleted_at !== null || ! $hasPermission || ! $actor->can('assign', $task)) {
            $this->deny();
        }
        if (! $actor->isSuperAdmin() && ! DB::table('property_user')
            ->where('property_id', $propertyId)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->exists()) {
            $this->deny();
        }
    }

    private function lockRoom(string $propertyId, string $roomId): Room
    {
        $room = Room::withoutGlobalScopes()
            ->whereKey($roomId)
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
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
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();
        if (! $task) {
            $this->deny();
        }

        return $task;
    }

    private function lockActiveAssignments(CleaningTask $task): \Illuminate\Database\Eloquent\Collection
    {
        return TaskAssignment::withoutGlobalScopes()
            ->where('property_id', $task->property_id)
            ->where('cleaning_task_id', $task->id)
            ->where('status', AssignmentStatusEnum::Active)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function lockTargetUser(string $attendantId): ?User
    {
        return User::withoutGlobalScopes()->whereKey($attendantId)->lock('FOR SHARE')->first();
    }

    private function lockMembership(string $propertyId, string $attendantId): ?object
    {
        return DB::table('property_user')
            ->where('property_id', $propertyId)
            ->where('user_id', $attendantId)
            ->lock('FOR SHARE')
            ->first();
    }

    private function lockDepartment(string $departmentId): ?Department
    {
        return Department::withoutGlobalScopes()->whereKey($departmentId)->lock('FOR SHARE')->first();
    }

    private function lockIdempotencyEvidence(string $propertyId, string $key): ?TaskAssignment
    {
        return TaskAssignment::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();
    }

    private function assertTargetEligible(
        ?User $target,
        ?object $membership,
        ?Department $department,
        string $propertyId,
        string $departmentId,
    ): void {
        if (
            ! $target
            || ! $target->is_active
            || $target->deleted_at !== null
            || ! $membership
            || $membership->status !== 'active'
        ) {
            throw new DomainException(self::ATTENDANT_NOT_ELIGIBLE);
        }
        if (
            ! $department
            || $department->property_id !== $propertyId
            || $department->id !== $departmentId
            || ! $department->is_active
            || $department->deleted_at !== null
            || $target->department_id !== $department->id
        ) {
            throw new DomainException(self::DEPARTMENT_NOT_ELIGIBLE);
        }
    }

    private function assertTaskRoom(CleaningTask $task, Room $room, string $propertyId): void
    {
        if ($task->property_id !== $propertyId || $task->room_id !== $room->id || $room->property_id !== $propertyId) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }
    }

    private function replayOrConflict(
        TaskAssignment $existing,
        CleaningTask $task,
        ?User $target,
        ?Department $department,
        string $operation,
        string $hash,
    ): HousekeepingTaskAssignmentResult {
        if (
            $existing->cleaning_task_id !== $task->id
            || $existing->assignment_action !== $operation
            || ! hash_equals((string) $existing->source_hash, $hash)
            || ! $target
            || ! $department
            || $existing->user_id !== $target->id
            || $existing->department_id !== $department->id
        ) {
            throw new DomainException(self::IDEMPOTENCY_CONFLICT);
        }

        return $this->result($existing, $task->fresh(), $target, $department, true);
    }

    private function close(TaskAssignment $assignment, AssignmentStatusEnum $status, User $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }
        $closedAt = now();
        $assignment->update([
            'status' => $status,
            'closed_at' => $closedAt,
            'closed_by' => $actor->id,
            'closure_reason' => $reason,
            'completed_at' => $status === AssignmentStatusEnum::Completed ? $closedAt : null,
        ]);
    }

    /** @param array<string, mixed> $newValues */
    private function recordAudit(string $event, User $actor, TaskAssignment $assignment, array $newValues): void
    {
        AuditLog::record([
            'property_id' => $assignment->property_id,
            'user_id' => $actor->id,
            'event' => $event,
            'auditable_type' => TaskAssignment::class,
            'auditable_id' => $assignment->id,
            'old_values' => [],
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'url' => request()?->fullUrl(),
            'tags' => ['housekeeping-task-assignment', $assignment->property_id, $assignment->cleaning_task_id],
        ]);
    }

    private function result(
        TaskAssignment $assignment,
        CleaningTask $task,
        User $target,
        Department $department,
        bool $replayed,
    ): HousekeepingTaskAssignmentResult {
        return new HousekeepingTaskAssignmentResult(
            $assignment->fresh(),
            $task,
            (string) $target->name,
            (string) $department->name,
            $replayed,
        );
    }

    private function sourceHash(
        string $operation,
        string $propertyId,
        string $taskId,
        string $targetId,
        string $departmentId,
        ?string $expectedActiveAssignmentId,
    ): string {
        return hash('sha256', json_encode([
            'operation' => $operation,
            'property_id' => $propertyId,
            'cleaning_task_id' => $taskId,
            'target_user_id' => $targetId,
            'department_id' => $departmentId,
            'expected_active_assignment_id' => $expectedActiveAssignmentId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeIdempotencyKey(string $key): string
    {
        $key = trim($key);
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,159}\z/', $key) !== 1) {
            throw new DomainException(self::IDEMPOTENCY_CONFLICT);
        }

        return $key;
    }

    private function normalizeExpectedAssignmentId(?string $assignmentId): ?string
    {
        $assignmentId = trim((string) $assignmentId);
        if ($assignmentId === '') {
            return null;
        }
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $assignmentId) !== 1) {
            throw new DomainException(self::STALE_ACTIVE_ASSIGNMENT);
        }

        return $assignmentId;
    }

    private function deny(): never
    {
        throw new HttpException(403, self::NOT_AUTHORIZED);
    }
}
