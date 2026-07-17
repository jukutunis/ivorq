<?php

namespace Modules\Operations\NightAudit\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Modules\Operations\NightAudit\Models\NightAuditRun;
use RuntimeException;

class NightAuditRunStartService
{
    public const ERROR_BUSINESS_DATE_UNAVAILABLE = 'NA_A1_BUSINESS_DATE_UNAVAILABLE';
    public const ERROR_MULTIPLE_ACTIVE_RUNS = 'NA_A1_MULTIPLE_ACTIVE_RUNS';
    public const ERROR_CONTEXT_CONFLICT = 'NA_A1_ACTIVE_RUN_CONTEXT_CONFLICT';
    public const ERROR_INVALID_BUSINESS_DATE = 'NA_A1_INVALID_BUSINESS_DATE_PROJECTION';

    public function __construct(
        private readonly NightAuditAuthorizationService $authorization,
        private readonly NightAuditBusinessDateDependencyService $businessDateDependency,
    ) {}

    public function start(User $actor): NightAuditRun
    {
        $this->authorization->authorizeStart($actor);
        $preLockEvidence = $this->currentBusinessDateOrFail($actor);

        return DB::transaction(function () use ($actor, $preLockEvidence): NightAuditRun {
            $authorizedProperty = $this->authorization->authorizeStart($actor);
            $insideEvidence = $this->currentBusinessDateOrFail($actor);
            $this->assertSameSourceIdentity($preLockEvidence, $insideEvidence);
            $this->assertEvidenceProperty($insideEvidence, $authorizedProperty->id);

            $lockedProperty = DB::table('properties')
                ->where('id', $authorizedProperty->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedProperty) {
                throw new AuthorizationException(NightAuditAuthorizationService::FAILURE_MESSAGE);
            }

            $postLockProperty = $this->authorization->authorizeStart($actor);
            if ((string) $postLockProperty->id !== (string) $authorizedProperty->id
                || ! $this->isTrue($lockedProperty->is_active)
                || (string) $lockedProperty->company_id !== (string) $postLockProperty->company_id) {
                throw new AuthorizationException(NightAuditAuthorizationService::FAILURE_MESSAGE);
            }

            $businessDate = PropertyBusinessDate::withoutGlobalScopes()
                ->whereKey($insideEvidence['property_business_date_id'])
                ->where('property_id', $postLockProperty->id)
                ->lockForUpdate()
                ->first();

            $finalEvidence = $this->currentBusinessDateOrFail($actor);
            $this->assertSameSourceIdentity($insideEvidence, $finalEvidence);
            $this->assertEvidenceProperty($finalEvidence, $postLockProperty->id);
            $this->assertLockedBusinessDate($businessDate, $finalEvidence);

            $activeRuns = NightAuditRun::withoutGlobalScopes()
                ->where('property_id', $postLockProperty->id)
                ->where('status', NightAuditRunStatusEnum::InProgress->value)
                ->lockForUpdate()
                ->orderBy('created_at')
                ->get();

            if ($activeRuns->count() > 1) {
                throw new RuntimeException(self::ERROR_MULTIPLE_ACTIVE_RUNS);
            }

            if ($activeRuns->count() === 1) {
                $existing = $activeRuns->first();
                NightAuditLockProjectionService::assertRunEvidence($existing, $finalEvidence);
                return $existing;
            }

            $attemptNumber = ((int) NightAuditRun::withoutGlobalScopes()
                ->where('property_business_date_id', $businessDate->id)
                ->max('attempt_number')) + 1;

            return NightAuditRun::create([
                'property_id' => $postLockProperty->id,
                'property_business_date_id' => $businessDate->id,
                'business_date_snapshot' => $finalEvidence['business_date'],
                'property_timezone_snapshot' => $finalEvidence['property_timezone'],
                'attempt_number' => $attemptNumber,
                'status' => NightAuditRunStatusEnum::InProgress,
                'started_by' => $actor->id,
                'started_at' => now('UTC'),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        }, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentBusinessDateOrFail(User $actor): array
    {
        $evidence = $this->businessDateDependency->project($actor);

        if (($evidence['status'] ?? null) === NightAuditBusinessDateDependencyService::STATUS_UNAVAILABLE) {
            throw new RuntimeException(self::ERROR_BUSINESS_DATE_UNAVAILABLE, 0, new RuntimeException((string) $evidence['source_status']));
        }

        if (($evidence['status'] ?? null) !== NightAuditBusinessDateDependencyService::STATUS_OPEN) {
            throw new DomainException(self::ERROR_INVALID_BUSINESS_DATE);
        }

        return $evidence;
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function assertEvidenceProperty(array $evidence, string $propertyId): void
    {
        if (($evidence['property_id'] ?? null) !== $propertyId) {
            throw new DomainException(self::ERROR_INVALID_BUSINESS_DATE);
        }
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function assertSameSourceIdentity(array $expected, array $actual): void
    {
        foreach (['property_id', 'property_business_date_id', 'source_fingerprint'] as $field) {
            if ((string) ($expected[$field] ?? '') !== (string) ($actual[$field] ?? '')) {
                throw new DomainException(self::ERROR_INVALID_BUSINESS_DATE);
            }
        }
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function assertLockedBusinessDate(?PropertyBusinessDate $businessDate, array $evidence): void
    {
        if (! $businessDate
            || (string) $businessDate->id !== (string) $evidence['property_business_date_id']
            || (string) $businessDate->property_id !== (string) $evidence['property_id']
            || $businessDate->business_date?->format('Y-m-d') !== (string) $evidence['business_date']
            || (string) $businessDate->timezone_snapshot !== (string) $evidence['property_timezone']
            || $businessDate->status !== PropertyBusinessDateStatusEnum::Open
            || $businessDate->is_open !== true
            || (string) $businessDate->opened_by !== (string) $evidence['opened_by']
            || $businessDate->opened_at?->utc()->toISOString() !== (string) $evidence['opened_at']) {
            throw new DomainException(self::ERROR_INVALID_BUSINESS_DATE);
        }
    }

    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
