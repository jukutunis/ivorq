<?php

namespace Modules\Operations\NightAudit\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService;
use Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext;
use Modules\Foundation\User\Models\User;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Modules\Operations\NightAudit\Models\NightAuditRun;
use RuntimeException;

class NightAuditRunAbortService
{
    public const ERROR_INVALID_REASON = 'NA_A1_INVALID_ABORT_REASON';
    public const ERROR_ACTIVE_RUN_NOT_FOUND = 'NA_A1_ACTIVE_RUN_NOT_FOUND';
    public const ERROR_MULTIPLE_ACTIVE_RUNS = 'NA_A1_MULTIPLE_ACTIVE_RUNS';
    public const ERROR_CONTEXT_CONFLICT = 'NA_A1_ACTIVE_RUN_CONTEXT_CONFLICT';
    public const ERROR_INVALID_BUSINESS_DATE = 'NA_A1_INVALID_BUSINESS_DATE_PROJECTION';

    public function __construct(
        private readonly NightAuditAuthorizationService $authorization,
        private readonly NightAuditBusinessDateDependencyService $businessDateDependency,
        private readonly PropertyBusinessDateOperationalLockService $operationalLock,
    ) {}

    public function abort(User $actor, string $reason): NightAuditRun
    {
        $reason = $this->sanitizeReason($reason);
        $this->authorization->authorizeAbort($actor);
        $preLockEvidence = $this->currentBusinessDateOrFail($actor);

        return DB::transaction(function () use ($actor, $reason, $preLockEvidence): NightAuditRun {
            $authorizedProperty = $this->authorization->authorizeAbort($actor);
            $insideEvidence = $this->currentBusinessDateOrFail($actor);
            $this->assertSameSourceIdentity($preLockEvidence, $insideEvidence);
            $this->assertEvidenceProperty($insideEvidence, $authorizedProperty->id);

            $this->acquireOperationalLock(
                $actor,
                (string) $authorizedProperty->company_id,
                (string) $authorizedProperty->id,
                $insideEvidence
            );

            $postLockProperty = $this->authorization->authorizeAbort($actor);
            if ((string) $postLockProperty->id !== (string) $authorizedProperty->id
                || (string) $postLockProperty->company_id !== (string) $authorizedProperty->company_id) {
                throw new AuthorizationException(NightAuditAuthorizationService::FAILURE_MESSAGE);
            }

            $finalEvidence = $this->currentBusinessDateOrFail($actor);
            $this->assertSameSourceIdentity($insideEvidence, $finalEvidence);
            $this->assertEvidenceProperty($finalEvidence, $postLockProperty->id);

            $activeRuns = NightAuditRun::withoutGlobalScopes()
                ->where('property_id', $postLockProperty->id)
                ->where('status', NightAuditRunStatusEnum::InProgress->value)
                ->lockForUpdate()
                ->orderBy('created_at')
                ->get();

            if ($activeRuns->count() === 0) {
                throw new RuntimeException(self::ERROR_ACTIVE_RUN_NOT_FOUND);
            }

            if ($activeRuns->count() > 1) {
                throw new RuntimeException(self::ERROR_MULTIPLE_ACTIVE_RUNS);
            }

            $run = $activeRuns->first();
            NightAuditLockProjectionService::assertRunEvidence($run, $finalEvidence);

            $run->forceFill([
                'status' => NightAuditRunStatusEnum::Aborted,
                'aborted_by' => $actor->id,
                'aborted_at' => now('UTC'),
                'abort_reason' => $reason,
                'updated_by' => $actor->id,
            ]);
            $run->save();

            return $run->fresh();
        }, 1);
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function acquireOperationalLock(User $actor, string $companyId, string $propertyId, array $evidence): PropertyBusinessDateOperationalLockContext
    {
        try {
            return $this->operationalLock->acquire($companyId, $propertyId, $evidence);
        } catch (DomainException|RuntimeException $exception) {
            if ($exception->getMessage() !== PropertyBusinessDateOperationalLockService::ERROR_CONTEXT_CHANGED
                && $exception->getMessage() !== PropertyBusinessDateOperationalLockService::ERROR_PROPERTY_LOCK_UNAVAILABLE
                && $exception->getMessage() !== PropertyBusinessDateOperationalLockService::ERROR_BUSINESS_DATE_LOCK_UNAVAILABLE) {
                throw $exception;
            }

            $this->authorization->authorizeAbort($actor);
            throw new DomainException(self::ERROR_INVALID_BUSINESS_DATE, 0, $exception);
        }
    }

    private function sanitizeReason(string $reason): string
    {
        $reason = trim($reason);

        if (strlen($reason) < 10 || strlen($reason) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
            throw new DomainException(self::ERROR_INVALID_REASON);
        }

        return $reason;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentBusinessDateOrFail(User $actor): array
    {
        $evidence = $this->businessDateDependency->project($actor);

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

}
