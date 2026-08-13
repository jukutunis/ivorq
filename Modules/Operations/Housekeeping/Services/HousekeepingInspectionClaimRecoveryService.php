<?php

namespace Modules\Operations\Housekeeping\Services;

use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\HousekeepingInspectionClaimReassignment;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\ValueObjects\HousekeepingInspectionClaimRecoveryResult;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The only production writer for controlled Package 19 claim recovery evidence. */
class HousekeepingInspectionClaimRecoveryService
{
    public const INTENT = 'housekeeping-inspection-claim-reassignment';

    public const INTERVENE_PERMISSION = 'housekeeping.inspection.approve';

    public const REPLACEMENT_PERMISSION = 'housekeeping.inspection.conduct';

    public const EVIDENCE_VERSION = 1;

    public const NOT_AUTHORIZED = 'HK_INSPECTION_CLAIM_RECOVERY_NOT_AUTHORIZED';

    public const ORIGINAL_STILL_ELIGIBLE = 'HK_INSPECTION_CLAIM_RECOVERY_ORIGINAL_STILL_ELIGIBLE';

    public const REPLACEMENT_INVALID = 'HK_INSPECTION_CLAIM_RECOVERY_REPLACEMENT_INVALID';

    public const REPLACEMENT_ORIGINAL_PROHIBITED = 'HK_INSPECTION_CLAIM_RECOVERY_REPLACEMENT_ORIGINAL_PROHIBITED';

    public const REPLACEMENT_CLEANER_PROHIBITED = 'HK_INSPECTION_CLAIM_RECOVERY_REPLACEMENT_CLEANER_PROHIBITED';

    public const SOURCE_CONFLICT = 'HK_INSPECTION_CLAIM_RECOVERY_SOURCE_CONFLICT';

    public const IDEMPOTENCY_CONFLICT = 'HK_INSPECTION_CLAIM_RECOVERY_IDEMPOTENCY_CONFLICT';

    public const ALREADY_COMPLETED = 'HK_INSPECTION_CLAIM_RECOVERY_ALREADY_COMPLETED';

    public const CONFIRMATION_REQUIRED = 'HK_INSPECTION_CLAIM_RECOVERY_CONFIRMATION_REQUIRED';

    public function __construct(
        private readonly CurrentPropertyService $currentProperty,
        private readonly SensitiveActionConfirmationService $confirmation,
    ) {}

    public function reassignmentContext(User $actor, string $inspectionId): array
    {
        $propertyId = $this->activePropertyId();
        $inspection = $this->scopedInspection($propertyId, $inspectionId);
        $reassignment = $this->recoveryForInspection($propertyId, $inspectionId);
        $originalCode = $reassignment?->original_ineligibility_code;
        if (! $reassignment) {
            try {
                $originalCode = $this->originalIneligibilityCode($propertyId, (string) $inspection->supervisor_id);
            } catch (DomainException) {
                $originalCode = null;
            }
        }

        $mayIntervene = ! $reassignment
            && $originalCode !== null
            && $inspection->claim_evidence_version === HousekeepingInspectionClaimService::EVIDENCE_VERSION
            && $inspection->status === InspectionStatusEnum::InProgress
            && $this->isEligible($actor, $propertyId, self::INTERVENE_PERMISSION)
            && $actor->can('reassignClaim', $inspection);
        $isRecoveryEligible = ! $reassignment
            && $originalCode !== null
            && $inspection->claim_evidence_version === HousekeepingInspectionClaimService::EVIDENCE_VERSION
            && $inspection->status === InspectionStatusEnum::InProgress;

        return [
            'is_recovery_eligible' => $isRecoveryEligible,
            'has_reassignment' => $reassignment !== null,
            'original_claimant_id' => $inspection->supervisor_id,
            'original_claimant_name' => $inspection->relationLoaded('inspector') ? $inspection->inspector?->name : null,
            'effective_claimant_id' => $reassignment?->replacement_claimant_id ?? $inspection->supervisor_id,
            'effective_claimant_name' => $reassignment?->replacementClaimant?->name
                ?? ($inspection->relationLoaded('inspector') ? $inspection->inspector?->name : null),
            'original_ineligibility_code' => $originalCode,
            'may_intervene' => $isRecoveryEligible && $mayIntervene,
            'replacement_candidates' => $mayIntervene
                ? $this->replacementCandidates($propertyId, (string) $inspection->supervisor_id, (string) $inspection->task?->completed_by)
                : [],
            'reassignment' => $reassignment ? $this->summary($reassignment) : null,
        ];
    }

    public function confirmReassignment(
        User $actor,
        string $inspectionId,
        string $replacementId,
        string $reason,
        string $idempotencyKey,
        string $password,
    ): array {
        $propertyId = $this->activePropertyId();
        [$reason, $idempotencyKey] = $this->normalizeInputs($reason, $idempotencyKey);
        $hash = DB::transaction(fn () => $this->lockedCommandContext(
            $actor, $propertyId, $inspectionId, $replacementId, $reason, $idempotencyKey, false
        )['source_hash']);

        $this->confirmation->confirm(
            $actor,
            self::INTENT,
            $password,
            session('active_company_id'),
            $propertyId,
            $hash,
        );

        return ['intent' => self::INTENT, 'confirmed' => true];
    }

    public function reassign(
        User $actor,
        string $inspectionId,
        string $replacementId,
        string $reason,
        string $idempotencyKey,
    ): HousekeepingInspectionClaimRecoveryResult {
        $propertyId = $this->activePropertyId();
        [$reason, $idempotencyKey] = $this->normalizeInputs($reason, $idempotencyKey);

        try {
            $result = $this->reassignInTransaction($actor, $propertyId, $inspectionId, $replacementId, $reason, $idempotencyKey);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505' || ! str_contains($exception->getMessage(), 'hk_p19_reassignment_')) {
                throw $exception;
            }
            $result = $this->reassignInTransaction($actor, $propertyId, $inspectionId, $replacementId, $reason, $idempotencyKey);
        }

        if (! $result->replayed) {
            $this->confirmation->invalidate($actor, self::INTENT, session('active_company_id'), $propertyId);
        }

        return $result;
    }

    public function effectiveClaimantId(string $propertyId, RoomInspection $inspection): ?string
    {
        return $this->recoveryForInspection($propertyId, $inspection->id)?->replacement_claimant_id
            ?? $inspection->supervisor_id;
    }

    public function recoveryForInspection(string $propertyId, string $inspectionId): ?HousekeepingInspectionClaimReassignment
    {
        return HousekeepingInspectionClaimReassignment::query()
            ->with(['originalClaimant', 'replacementClaimant', 'intervenor'])
            ->where('property_id', $propertyId)
            ->where('room_inspection_id', $inspectionId)
            ->first();
    }

    private function reassignInTransaction(User $actor, string $propertyId, string $inspectionId, string $replacementId, string $reason, string $key): HousekeepingInspectionClaimRecoveryResult
    {
        return DB::transaction(function () use ($actor, $propertyId, $inspectionId, $replacementId, $reason, $key) {
            $context = $this->lockedCommandContext($actor, $propertyId, $inspectionId, $replacementId, $reason, $key, true);
            if ($context['replay'] instanceof HousekeepingInspectionClaimReassignment) {
                return new HousekeepingInspectionClaimRecoveryResult($context['replay']->fresh(), true);
            }

            $metadata = $this->confirmation->confirmationMetadataFor(
                $actor, self::INTENT, session('active_company_id'), $propertyId
            );
            if (! $metadata || ! hash_equals($context['source_hash'], (string) ($metadata['commercial_evidence_hash'] ?? ''))) {
                throw new DomainException(self::CONFIRMATION_REQUIRED);
            }

            $occurredAt = now();
            $reassignment = HousekeepingInspectionClaimReassignment::create([
                'property_id' => $propertyId,
                'room_inspection_id' => $inspectionId,
                'original_claimant_id' => $context['inspection']->supervisor_id,
                'replacement_claimant_id' => $replacementId,
                'intervened_by' => $actor->id,
                'original_ineligibility_code' => $context['ineligibility_code'],
                'reason' => $reason,
                'idempotency_key' => $key,
                'source_hash' => $context['source_hash'],
                'evidence_version' => self::EVIDENCE_VERSION,
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
            ]);

            AuditLog::record([
                'property_id' => $propertyId,
                'user_id' => $actor->id,
                'event' => 'housekeeping_inspection_claim_reassigned',
                'auditable_type' => RoomInspection::class,
                'auditable_id' => $inspectionId,
                'old_values' => ['effective_claimant_id' => $context['inspection']->supervisor_id],
                'new_values' => [
                    'inspection_id' => $inspectionId,
                    'cleaning_task_id' => $context['inspection']->cleaning_task_id,
                    'original_claimant_id' => $context['inspection']->supervisor_id,
                    'effective_claimant_id' => $replacementId,
                    'replacement_claimant_id' => $replacementId,
                    'intervened_by' => $actor->id,
                    'original_ineligibility_code' => $context['ineligibility_code'],
                    'reason' => $reason,
                    'idempotency_key' => $key,
                    'reassignment_evidence_version' => self::EVIDENCE_VERSION,
                    'source_hash' => $context['source_hash'],
                    'idempotency_digest' => hash('sha256', $key),
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'url' => request()?->fullUrl(),
                'tags' => ['housekeeping-inspection-claim-reassignment', $propertyId, $inspectionId],
            ]);

            return new HousekeepingInspectionClaimRecoveryResult($reassignment, false);
        });
    }

    /** Canonical order: Room -> Task -> completed assignment -> Inspection -> recovery -> Users -> memberships. */
    private function lockedCommandContext(User $actor, string $propertyId, string $inspectionId, string $replacementId, string $reason, string $key, bool $allowReplay): array
    {
        $preview = $this->scopedInspection($propertyId, $inspectionId);
        $room = Room::withoutGlobalScopes()->whereKey($preview->room_id)->where('property_id', $propertyId)->lockForUpdate()->first();
        $task = CleaningTask::withoutGlobalScopes()->whereKey($preview->cleaning_task_id)->where('property_id', $propertyId)->lockForUpdate()->first();
        $assignments = TaskAssignment::withoutGlobalScopes()->where('property_id', $propertyId)
            ->where('cleaning_task_id', $preview->cleaning_task_id)->where('status', 'completed')
            ->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get();
        $inspection = RoomInspection::withoutGlobalScopes()->whereKey($inspectionId)->where('property_id', $propertyId)
            ->whereNull('deleted_at')->lockForUpdate()->first();
        $existing = HousekeepingInspectionClaimReassignment::query()->where('property_id', $propertyId)
            ->where(fn ($query) => $query->where('room_inspection_id', $inspectionId)->orWhere('idempotency_key', $key))
            ->orderBy('id')->lockForUpdate()->get();

        if (! $room || ! $task || ! $inspection) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }
        $this->assertIntervenor($actor, $inspection, $propertyId);
        $this->assertCanonicalSource($inspection, $task, $room, $assignments, $propertyId);

        $committedForInspection = $existing->firstWhere('room_inspection_id', $inspectionId);
        $committedForKey = $existing->firstWhere('idempotency_key', $key);
        if ($committedForInspection || $committedForKey) {
            if ($allowReplay && $committedForInspection && $committedForKey && $committedForInspection->id === $committedForKey->id) {
                $expected = $this->sourceHash($inspection, $task, $replacementId, $actor->id, $committedForInspection->original_ineligibility_code, $reason, $key);
                if ($committedForInspection->replacement_claimant_id === $replacementId
                    && $committedForInspection->intervened_by === $actor->id
                    && $committedForInspection->reason === $reason
                    && hash_equals($expected, $committedForInspection->source_hash)) {
                    return ['replay' => $committedForInspection, 'source_hash' => $expected];
                }
                throw new DomainException(self::IDEMPOTENCY_CONFLICT);
            }
            throw new DomainException($committedForInspection ? self::ALREADY_COMPLETED : self::IDEMPOTENCY_CONFLICT);
        }

        $userIds = array_values(array_unique(array_filter([$inspection->supervisor_id, $replacementId, $actor->id])));
        sort($userIds);
        User::withoutGlobalScopes()->whereIn('id', $userIds)->orderBy('id')->lock('FOR SHARE')->get();
        DB::table('property_user')->where('property_id', $propertyId)->whereIn('user_id', $userIds)
            ->orderBy('user_id')->lock('FOR SHARE')->get();

        $ineligibilityCode = $this->originalIneligibilityCode($propertyId, (string) $inspection->supervisor_id);
        if ($replacementId === $inspection->supervisor_id) {
            throw new DomainException(self::REPLACEMENT_ORIGINAL_PROHIBITED);
        }
        if ($replacementId === $task->completed_by) {
            throw new DomainException(self::REPLACEMENT_CLEANER_PROHIBITED);
        }
        $replacement = User::withoutGlobalScopes()->find($replacementId);
        if (! $replacement || ! $this->isEligible($replacement, $propertyId, self::REPLACEMENT_PERMISSION)) {
            throw new DomainException(self::REPLACEMENT_INVALID);
        }

        return [
            'inspection' => $inspection,
            'ineligibility_code' => $ineligibilityCode,
            'source_hash' => $this->sourceHash($inspection, $task, $replacementId, $actor->id, $ineligibilityCode, $reason, $key),
            'replay' => null,
        ];
    }

    private function assertCanonicalSource(RoomInspection $inspection, CleaningTask $task, Room $room, Collection $assignments, string $propertyId): void
    {
        $taskType = $task->task_type instanceof \BackedEnum ? $task->task_type->value : (string) $task->task_type;
        $inspectionType = $inspection->inspection_type instanceof \BackedEnum ? $inspection->inspection_type->value : (string) $inspection->inspection_type;
        if ($inspection->property_id !== $propertyId || $task->property_id !== $propertyId || $room->property_id !== $propertyId
            || $inspection->room_id !== $room->id || $inspection->cleaning_task_id !== $task->id || $task->room_id !== $room->id
            || $taskType !== 'checkout_cleaning' || $inspectionType !== 'post_cleaning'
            || $task->status !== TaskStatusEnum::Completed || $task->completed_by === null
            || $inspection->status !== InspectionStatusEnum::InProgress || $inspection->claim_evidence_version !== 1
            || ! $inspection->supervisor_id || ! $inspection->claimed_at || ! $inspection->claim_idempotency_key
            || preg_match('/\A[0-9a-f]{64}\z/', (string) $inspection->claim_source_hash) !== 1) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }
        $p17Hash = $this->p17SourceHash($propertyId, $inspection, $task);
        if (! hash_equals($p17Hash, (string) $inspection->claim_source_hash)) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }
        if ($assignments->count() > 1) {
            throw new DomainException(self::SOURCE_CONFLICT);
        }
        if ($assignments->isNotEmpty()) {
            $assignment = $assignments->first();
            if ($assignment->user_id !== $task->completed_by || $assignment->attendant_id !== $task->completed_by
                || $assignment->closed_by !== $task->completed_by || ! $assignment->closed_at || ! $assignment->completed_at) {
                throw new DomainException(self::SOURCE_CONFLICT);
            }
        }
    }

    private function sourceHash(RoomInspection $inspection, CleaningTask $task, string $replacementId, string $actorId, string $code, string $reason, string $key): string
    {
        $values = [self::EVIDENCE_VERSION, $inspection->property_id, $inspection->id, $inspection->supervisor_id,
            $inspection->claimed_at->format('Y-m-d\TH:i:s.u'), $inspection->claim_idempotency_key,
            $inspection->claim_source_hash, $inspection->claim_evidence_version, $task->id, $task->completed_by,
            $inspection->supervisor_id, $replacementId, $actorId, $code, $reason, $key];
        if (DB::getDriverName() === 'pgsql') {
            return (string) DB::scalar('SELECT hk_p19_inspection_claim_reassignment_source_hash(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $values);
        }

        return hash('sha256', implode('|', array_merge(['housekeeping_inspection_claim_reassignment'], $values)));
    }

    private function p17SourceHash(string $propertyId, RoomInspection $inspection, CleaningTask $task): string
    {
        $values = [1, $propertyId, $inspection->id, $inspection->room_id, $task->id, $task->completed_by, $inspection->supervisor_id];

        return DB::getDriverName() === 'pgsql'
            ? (string) DB::scalar('SELECT hk_p17_inspection_claim_source_hash(?, ?, ?, ?, ?, ?, ?)', $values)
            : hash('sha256', implode('|', array_merge(['post_cleaning_inspection_claim'], $values)));
    }

    private function originalIneligibilityCode(string $propertyId, string $originalId): string
    {
        $original = User::withoutGlobalScopes()->find($originalId);
        if (! $original || ! $original->is_active || $original->deleted_at !== null) {
            return 'original_user_inactive_or_deleted';
        }
        if (! DB::table('property_user')->where('property_id', $propertyId)->where('user_id', $originalId)->where('status', 'active')->exists()) {
            return 'original_property_membership_inactive_or_missing';
        }
        if (! $this->hasPermission($original, self::REPLACEMENT_PERMISSION)) {
            return 'original_conduct_permission_missing';
        }
        throw new DomainException(self::ORIGINAL_STILL_ELIGIBLE);
    }

    private function assertIntervenor(User $actor, RoomInspection $inspection, string $propertyId): void
    {
        if (! $this->isEligible($actor, $propertyId, self::INTERVENE_PERMISSION) || ! $actor->can('reassignClaim', $inspection)) {
            throw new HttpException(403, self::NOT_AUTHORIZED);
        }
    }

    private function isEligible(User $user, string $propertyId, string $permission): bool
    {
        return $user->is_active && $user->deleted_at === null && $this->hasPermission($user, $permission)
            && DB::table('property_user')->where('property_id', $propertyId)->where('user_id', $user->id)->where('status', 'active')->exists();
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    private function replacementCandidates(string $propertyId, string $originalId, string $cleanerId): array
    {
        return User::withoutGlobalScopes()->where('is_active', true)->whereNull('deleted_at')
            ->whereHas('properties', fn ($query) => $query->where('properties.id', $propertyId)->where('property_user.status', 'active'))
            ->whereNotIn('users.id', [$originalId, $cleanerId])->orderBy('name')->get()
            ->filter(fn (User $user) => $this->hasPermission($user, self::REPLACEMENT_PERMISSION))
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values()->all();
    }

    private function summary(HousekeepingInspectionClaimReassignment $reassignment): array
    {
        return [
            'id' => $reassignment->id,
            'original_claimant_id' => $reassignment->original_claimant_id,
            'original_claimant_name' => $reassignment->originalClaimant?->name,
            'replacement_claimant_id' => $reassignment->replacement_claimant_id,
            'replacement_claimant_name' => $reassignment->replacementClaimant?->name,
            'intervened_by' => $reassignment->intervened_by,
            'intervenor_name' => $reassignment->intervenor?->name,
            'original_ineligibility_code' => $reassignment->original_ineligibility_code,
            'reason' => $reassignment->reason,
            'occurred_at' => $reassignment->occurred_at,
            'evidence_version' => $reassignment->evidence_version,
        ];
    }

    private function normalizeInputs(string $reason, string $key): array
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        $key = trim($key);
        if ($reason === '' || mb_strlen($reason) > 1000 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,159}\z/', $key) !== 1) {
            throw new DomainException(self::IDEMPOTENCY_CONFLICT);
        }

        return [$reason, $key];
    }

    private function activePropertyId(): string
    {
        $propertyId = $this->currentProperty->resolveOrFail();
        $property = Property::withoutGlobalScopes()->join('companies as company', 'company.id', '=', 'properties.company_id')
            ->whereKey($propertyId)->where('properties.is_active', true)->whereNull('properties.deleted_at')
            ->where('company.is_active', true)->whereNull('company.deleted_at')->select('properties.*')->first();
        if (! $property || (session('active_company_id') !== null && $property->company_id !== session('active_company_id'))) {
            throw new HttpException(403, self::NOT_AUTHORIZED);
        }
        setPermissionsTeamId($propertyId);

        return $propertyId;
    }

    private function scopedInspection(string $propertyId, string $inspectionId): RoomInspection
    {
        $inspection = RoomInspection::withoutGlobalScopes()->with(['inspector', 'task.completedBy'])
            ->whereKey($inspectionId)->where('property_id', $propertyId)->whereNull('deleted_at')->first();
        if (! $inspection) {
            throw new HttpException(403, self::NOT_AUTHORIZED);
        }

        return $inspection;
    }
}
