<?php

namespace Modules\Operations\Housekeeping\Services;

use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\ValueObjects\HousekeepingInspectionClaimResult;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The only production write authority for a post-cleaning Inspection claim.
 *
 * Canonical lock order: Room -> CleaningTask -> completed TaskAssignment
 * evidence -> RoomInspection -> claimant User -> property membership.
 */
class HousekeepingInspectionClaimService
{
    public const CLAIM_PERMISSION = 'housekeeping.inspection.conduct';
    public const EVIDENCE_VERSION = 1;
    public const NOT_AUTHORIZED = 'HK_INSPECTION_CLAIM_NOT_AUTHORIZED';
    public const NOT_ELIGIBLE = 'HK_INSPECTION_CLAIM_NOT_ELIGIBLE';
    public const CLEANER_PROHIBITED = 'HK_INSPECTION_CLAIM_CLEANER_PROHIBITED';
    public const IDEMPOTENCY_CONFLICT = 'HK_INSPECTION_CLAIM_IDEMPOTENCY_CONFLICT';
    public const SOURCE_CONFLICT = 'HK_INSPECTION_CLAIM_SOURCE_CONFLICT';
    public const OWNERSHIP_REQUIRED = 'HK_INSPECTION_CLAIM_OWNERSHIP_REQUIRED';

    public function __construct(private readonly CurrentPropertyService $currentProperty) {}

    public function claim(User $actor, string $inspectionId, string $idempotencyKey): HousekeepingInspectionClaimResult
    {
        $propertyId = $this->activePropertyId();
        $preview = $this->scopedInspection($propertyId, $inspectionId);
        $this->authorize($actor, $preview, $propertyId);
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        try {
            return $this->claimInTransaction($actor, $propertyId, $preview, $inspectionId, $idempotencyKey);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505'
                || ! str_contains($exception->getMessage(), 'hk_p17_inspection_claim_property_key_unique')) {
                throw $exception;
            }

            // A concurrent exact request may have committed while this transaction
            // waited on the Property-scoped unique key. Re-run once through the same
            // lock/revalidation path so exact replay succeeds and conflicts stay closed.
            return $this->claimInTransaction($actor, $propertyId, $preview, $inspectionId, $idempotencyKey);
        }
    }

    /**
     * @param Collection<int, TaskAssignment> $completedAssignments
     */
    public function assertTerminalAuthority(
        User $actor,
        string $propertyId,
        RoomInspection $inspection,
        CleaningTask $task,
        Collection $completedAssignments,
    ): void {
        $this->authorize($actor, $inspection, $propertyId);
        $this->assertCompletedAssignmentEvidence($task, $completedAssignments);

        // Historical Package 13 rows can predate durable Package 17 evidence.
        // They never become Package 17 claims: retain only their recorded
        // supervisor replay boundary and never fabricate claim evidence.
        if ($inspection->claim_evidence_version === null) {
            if (
                $inspection->claimed_at !== null
                || $inspection->claim_idempotency_key !== null
                || $inspection->claim_source_hash !== null
                || $inspection->supervisor_id === null
                || $actor->id !== $inspection->supervisor_id
            ) {
                throw new DomainException(self::OWNERSHIP_REQUIRED);
            }

            return;
        }

        if (
            $inspection->claim_evidence_version !== self::EVIDENCE_VERSION
            || $inspection->supervisor_id === null
            || $inspection->claimed_at === null
            || trim((string) $inspection->claim_idempotency_key) === ''
            || preg_match('/\A[0-9a-f]{64}\z/', (string) $inspection->claim_source_hash) !== 1
        ) {
            throw new DomainException(self::OWNERSHIP_REQUIRED);
        }
        if ($task->completed_by === null) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }
        if ($actor->id !== $inspection->supervisor_id) {
            throw new DomainException(self::OWNERSHIP_REQUIRED);
        }
        if ($actor->id === $task->completed_by) {
            throw new DomainException(self::CLEANER_PROHIBITED);
        }

        $expectedHash = $this->sourceHash(
            self::EVIDENCE_VERSION,
            $propertyId,
            $inspection->id,
            (string) $inspection->room_id,
            $task->id,
            (string) $task->completed_by,
            (string) $inspection->supervisor_id,
        );
        if (! hash_equals($expectedHash, (string) $inspection->claim_source_hash)) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }
    }

    public function sourceHash(
        int $version,
        string $propertyId,
        string $inspectionId,
        string $roomId,
        string $taskId,
        string $cleanerId,
        string $claimantId,
    ): string {
        if (DB::getDriverName() === 'pgsql') {
            return (string) DB::scalar(
                'SELECT hk_p17_inspection_claim_source_hash(?, ?, ?, ?, ?, ?, ?)',
                [$version, $propertyId, $inspectionId, $roomId, $taskId, $cleanerId, $claimantId],
            );
        }

        return hash('sha256', implode('|', [
            'post_cleaning_inspection_claim',
            $version,
            $propertyId,
            $inspectionId,
            $roomId,
            $taskId,
            $cleanerId,
            $claimantId,
        ]));
    }

    private function claimInTransaction(
        User $actor,
        string $propertyId,
        RoomInspection $preview,
        string $inspectionId,
        string $idempotencyKey,
    ): HousekeepingInspectionClaimResult {
        return DB::transaction(function () use ($actor, $propertyId, $preview, $inspectionId, $idempotencyKey) {
            $room = $this->lockRoom($propertyId, (string) $preview->room_id);
            $task = $this->lockTask($propertyId, (string) $preview->cleaning_task_id);
            $completedAssignments = $this->lockCompletedAssignments($task);
            $inspection = $this->lockInspection($propertyId, $inspectionId);
            $lockedActor = $this->lockActor($actor->id);
            $this->lockMembership($propertyId, $actor->id);

            $this->authorize($lockedActor, $inspection, $propertyId);
            $this->assertSource($inspection, $task, $room, $propertyId);
            $this->assertCompletedAssignmentEvidence($task, $completedAssignments);

            if ($task->completed_by === null) {
                throw new DomainException(self::SOURCE_CONFLICT);
            }
            if ($lockedActor->id === $task->completed_by) {
                throw new DomainException(self::CLEANER_PROHIBITED);
            }

            $sourceHash = $this->sourceHash(
                self::EVIDENCE_VERSION,
                $propertyId,
                $inspection->id,
                $room->id,
                $task->id,
                (string) $task->completed_by,
                $lockedActor->id,
            );

            $existingKey = RoomInspection::withoutGlobalScopes()
                ->where('property_id', $propertyId)
                ->where('claim_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existingKey) {
                return $this->replayOrConflict($existingKey, $inspection, $lockedActor, $idempotencyKey, $sourceHash);
            }

            if (
                $inspection->claim_evidence_version !== null
                || $inspection->claimed_at !== null
                || $inspection->claim_idempotency_key !== null
                || $inspection->claim_source_hash !== null
                || $inspection->supervisor_id !== null
            ) {
                return $this->replayOrConflict($inspection, $inspection, $lockedActor, $idempotencyKey, $sourceHash);
            }
            if ($inspection->status !== InspectionStatusEnum::Pending) {
                throw new DomainException(self::NOT_ELIGIBLE);
            }

            $inspection->forceFill([
                'status' => InspectionStatusEnum::InProgress,
                'supervisor_id' => $lockedActor->id,
                'claimed_at' => now(),
                'claim_idempotency_key' => $idempotencyKey,
                'claim_source_hash' => $sourceHash,
                'claim_evidence_version' => self::EVIDENCE_VERSION,
            ])->save();

            $this->recordAudit($lockedActor, $inspection, $task);

            return new HousekeepingInspectionClaimResult($inspection->fresh(), false);
        });
    }

    private function replayOrConflict(
        RoomInspection $existing,
        RoomInspection $requested,
        User $actor,
        string $key,
        string $sourceHash,
    ): HousekeepingInspectionClaimResult {
        if (
            $existing->id !== $requested->id
            || $existing->property_id !== $requested->property_id
            || $existing->supervisor_id !== $actor->id
            || $existing->claim_idempotency_key !== $key
            || $existing->claim_evidence_version !== self::EVIDENCE_VERSION
            || $existing->claimed_at === null
            || ! hash_equals((string) $existing->claim_source_hash, $sourceHash)
            || $existing->status !== InspectionStatusEnum::InProgress
        ) {
            throw new DomainException(self::IDEMPOTENCY_CONFLICT);
        }

        return new HousekeepingInspectionClaimResult($existing->fresh(), true);
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

    private function scopedInspection(string $propertyId, string $inspectionId): RoomInspection
    {
        $inspection = RoomInspection::withoutGlobalScopes()
            ->whereKey($inspectionId)
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
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

    /** @return Collection<int, TaskAssignment> */
    private function lockCompletedAssignments(CleaningTask $task): Collection
    {
        return TaskAssignment::withoutGlobalScopes()
            ->where('property_id', $task->property_id)
            ->where('cleaning_task_id', $task->id)
            ->where('status', 'completed')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function lockInspection(string $propertyId, string $inspectionId): RoomInspection
    {
        $inspection = RoomInspection::withoutGlobalScopes()
            ->whereKey($inspectionId)
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();
        if (! $inspection) {
            $this->deny();
        }

        return $inspection;
    }

    private function lockActor(string $actorId): User
    {
        $actor = User::withoutGlobalScopes()->whereKey($actorId)->lock('FOR SHARE')->first();
        if (! $actor || ! $actor->is_active || $actor->deleted_at !== null) {
            $this->deny();
        }

        return $actor;
    }

    private function lockMembership(string $propertyId, string $actorId): void
    {
        $membership = DB::table('property_user')
            ->where('property_id', $propertyId)
            ->where('user_id', $actorId)
            ->where('status', 'active')
            ->lock('FOR SHARE')
            ->first();
        if (! $membership) {
            $this->deny();
        }
    }

    private function assertSource(RoomInspection $inspection, CleaningTask $task, Room $room, string $propertyId): void
    {
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
            || $task->completed_by === null
        ) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }
    }

    /** @param Collection<int, TaskAssignment> $completedAssignments */
    private function assertCompletedAssignmentEvidence(CleaningTask $task, Collection $completedAssignments): void
    {
        if ($completedAssignments->isEmpty()) {
            return;
        }
        if ($completedAssignments->count() !== 1) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }

        $assignment = $completedAssignments->first();
        if (
            $assignment->user_id !== $task->completed_by
            || $assignment->attendant_id !== $task->completed_by
            || $assignment->closed_by !== $task->completed_by
            || $assignment->closed_at === null
            || $assignment->completed_at === null
        ) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }
    }

    private function authorize(User $actor, RoomInspection $inspection, string $propertyId): void
    {
        try {
            $hasPermission = $actor->hasPermissionTo(self::CLAIM_PERMISSION);
        } catch (PermissionDoesNotExist) {
            $hasPermission = false;
        }
        if (! $actor->is_active || $actor->deleted_at !== null || ! $hasPermission || ! $actor->can('conduct', $inspection)) {
            $this->deny();
        }
        if (! DB::table('property_user')
            ->where('property_id', $propertyId)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->exists()) {
            $this->deny();
        }
    }

    private function normalizeIdempotencyKey(string $key): string
    {
        $key = trim($key);
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,159}\z/', $key) !== 1) {
            throw new DomainException(self::IDEMPOTENCY_CONFLICT);
        }

        return $key;
    }

    private function recordAudit(User $actor, RoomInspection $inspection, CleaningTask $task): void
    {
        AuditLog::record([
            'property_id' => $inspection->property_id,
            'user_id' => $actor->id,
            'event' => 'housekeeping_inspection_claimed',
            'auditable_type' => RoomInspection::class,
            'auditable_id' => $inspection->id,
            'old_values' => ['status' => InspectionStatusEnum::Pending->value],
            'new_values' => [
                'status' => InspectionStatusEnum::InProgress->value,
                'inspection_id' => $inspection->id,
                'cleaning_task_id' => $task->id,
                'claimant_id' => $actor->id,
                'claim_evidence_version' => self::EVIDENCE_VERSION,
                'idempotency_digest' => hash('sha256', (string) $inspection->claim_idempotency_key),
                'source_hash' => $inspection->claim_source_hash,
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'url' => request()?->fullUrl(),
            'tags' => ['housekeeping-inspection-claim', $inspection->property_id, $inspection->id],
        ]);
    }

    private function deny(): never
    {
        throw new HttpException(403, self::NOT_AUTHORIZED);
    }
}
