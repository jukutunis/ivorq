<?php

namespace Modules\Operations\NightAudit\Services;

use DomainException;
use Modules\Foundation\User\Models\User;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Modules\Operations\NightAudit\Models\NightAuditRun;
use RuntimeException;

class NightAuditLockProjectionService
{
    public const STATUS_ACTIVE = 'NIGHT_AUDIT_LOCK_ACTIVE';
    public const STATUS_CLEAR = 'NIGHT_AUDIT_LOCK_CLEAR';
    public const STATUS_UNAVAILABLE = 'NIGHT_AUDIT_LOCK_EVIDENCE_UNAVAILABLE';
    public const SOURCE_PROVEN = 'NIGHT_AUDIT_RUN_SOURCE_PROVEN';
    public const SOURCE_UNAVAILABLE = 'NIGHT_AUDIT_RUN_SOURCE_UNAVAILABLE';
    public const ERROR_MULTIPLE_ACTIVE_RUNS = 'NA_A1_MULTIPLE_ACTIVE_RUNS';
    public const ERROR_INVALID_RUN = 'NA_A1_INVALID_RUN_EVIDENCE';
    public const ERROR_CONTEXT_CONFLICT = 'NA_A1_ACTIVE_RUN_CONTEXT_CONFLICT';

    public function __construct(
        private readonly NightAuditAuthorizationService $authorization,
        private readonly NightAuditBusinessDateDependencyService $businessDateDependency,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function project(User $actor): array
    {
        $property = $this->authorization->authorizeView($actor);
        $businessDate = $this->businessDateDependency->project($actor);

        if (($businessDate['status'] ?? null) === NightAuditBusinessDateDependencyService::STATUS_UNAVAILABLE) {
            return [
                'status' => self::STATUS_UNAVAILABLE,
                'source_status' => self::STATUS_UNAVAILABLE,
                'source_classification' => self::SOURCE_UNAVAILABLE,
                'close_lock_active' => null,
                'property_id' => null,
                'property_business_date_id' => null,
                'business_date' => null,
                'property_timezone' => null,
                'night_audit_run_id' => null,
                'attempt_number' => null,
                'run_status' => null,
                'started_by' => null,
                'started_at' => null,
                'evidence_unavailable_codes' => $businessDate['evidence_unavailable_codes'],
                'source_fingerprint' => null,
                'evaluated_at' => now()->toISOString(),
                'markers' => $this->markers(),
            ];
        }

        $activeRuns = NightAuditRun::withoutGlobalScopes()
            ->where('property_id', $property->id)
            ->where('status', NightAuditRunStatusEnum::InProgress->value)
            ->orderBy('created_at')
            ->get();

        if ($activeRuns->count() > 1) {
            throw new RuntimeException(self::ERROR_MULTIPLE_ACTIVE_RUNS);
        }

        if ($activeRuns->count() === 1) {
            $run = $activeRuns->first();
            $this->assertRunEvidence($run);
            if ((string) $run->property_business_date_id !== (string) $businessDate['property_business_date_id']) {
                throw new RuntimeException(self::ERROR_CONTEXT_CONFLICT);
            }

            return $this->active($businessDate, $run);
        }

        return $this->clear($businessDate);
    }

    /**
     * @param array<string, mixed> $businessDate
     * @return array<string, mixed>
     */
    private function active(array $businessDate, NightAuditRun $run): array
    {
        return [
            'status' => self::STATUS_ACTIVE,
            'source_status' => NightAuditRunStatusEnum::InProgress->value,
            'source_classification' => self::SOURCE_PROVEN,
            'close_lock_active' => true,
            'property_id' => $businessDate['property_id'],
            'property_business_date_id' => $businessDate['property_business_date_id'],
            'business_date' => $businessDate['business_date'],
            'property_timezone' => $businessDate['property_timezone'],
            'night_audit_run_id' => (string) $run->id,
            'attempt_number' => (int) $run->attempt_number,
            'run_status' => $run->status->value,
            'started_by' => (string) $run->started_by,
            'started_at' => $run->started_at?->utc()->toISOString(),
            'evidence_unavailable_codes' => [],
            'source_fingerprint' => $this->fingerprint([
                'property_id' => $businessDate['property_id'],
                'property_business_date_id' => $businessDate['property_business_date_id'],
                'business_date' => $businessDate['business_date'],
                'property_timezone' => $businessDate['property_timezone'],
                'night_audit_run_id' => (string) $run->id,
                'attempt_number' => (int) $run->attempt_number,
                'run_status' => $run->status->value,
                'started_by' => (string) $run->started_by,
                'started_at' => $run->started_at?->utc()->toISOString(),
                'close_lock_active' => true,
            ]),
            'evaluated_at' => now()->toISOString(),
            'markers' => $this->markers(),
        ];
    }

    /**
     * @param array<string, mixed> $businessDate
     * @return array<string, mixed>
     */
    private function clear(array $businessDate): array
    {
        return [
            'status' => self::STATUS_CLEAR,
            'source_status' => self::STATUS_CLEAR,
            'source_classification' => self::SOURCE_PROVEN,
            'close_lock_active' => false,
            'property_id' => $businessDate['property_id'],
            'property_business_date_id' => $businessDate['property_business_date_id'],
            'business_date' => $businessDate['business_date'],
            'property_timezone' => $businessDate['property_timezone'],
            'night_audit_run_id' => null,
            'attempt_number' => null,
            'run_status' => null,
            'started_by' => null,
            'started_at' => null,
            'evidence_unavailable_codes' => [],
            'source_fingerprint' => $this->fingerprint([
                'property_id' => $businessDate['property_id'],
                'property_business_date_id' => $businessDate['property_business_date_id'],
                'business_date' => $businessDate['business_date'],
                'property_timezone' => $businessDate['property_timezone'],
                'close_lock_active' => false,
            ]),
            'evaluated_at' => now()->toISOString(),
            'markers' => $this->markers(),
        ];
    }

    private function assertRunEvidence(NightAuditRun $run): void
    {
        if ($run->status !== NightAuditRunStatusEnum::InProgress
            || trim((string) $run->started_by) === ''
            || $run->started_at === null
            || trim((string) $run->property_timezone_snapshot) === ''
            || (int) $run->attempt_number < 1
            || $run->aborted_by !== null
            || $run->aborted_at !== null
            || $run->abort_reason !== null) {
            throw new DomainException(self::ERROR_INVALID_RUN);
        }
    }

    /**
     * @param array<string, mixed> $canonical
     */
    private function fingerprint(array $canonical): string
    {
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, string>
     */
    private function markers(): array
    {
        return [
            'ownership_marker' => 'Night Audit owns run and close-lock evidence.',
            'business_date_marker' => 'NA-A1 does not close or advance Business Date.',
            'checkpoint_marker' => 'NA-A1 does not run checkpoint orchestration.',
            'source_domain_marker' => 'Source domains remain owners of their own outcomes.',
            'front_desk_marker' => 'Front Desk does not consume this source until FD-B12.',
            'checkout_marker' => 'Checkout execution is not authorized.',
        ];
    }
}
