<?php

namespace Modules\Operations\NightAudit\Services;

use DomainException;
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
        $this->currentBusinessDateOrFail($actor);

        return DB::transaction(function () use ($actor): NightAuditRun {
            $property = $this->authorization->authorizeStart($actor);
            $evidence = $this->currentBusinessDateOrFail($actor);
            $this->assertEvidenceProperty($evidence, $property->id);

            DB::table('properties')
                ->where('id', $property->id)
                ->lockForUpdate()
                ->first();

            $businessDate = PropertyBusinessDate::withoutGlobalScopes()
                ->whereKey($evidence['property_business_date_id'])
                ->where('property_id', $property->id)
                ->lockForUpdate()
                ->first();

            if (! $businessDate
                || $businessDate->status !== PropertyBusinessDateStatusEnum::Open
                || $businessDate->is_open !== true
                || $businessDate->business_date->format('Y-m-d') !== $evidence['business_date']
                || (string) $businessDate->timezone_snapshot !== $evidence['property_timezone']) {
                throw new DomainException(self::ERROR_INVALID_BUSINESS_DATE);
            }

            $activeRuns = NightAuditRun::withoutGlobalScopes()
                ->where('property_id', $property->id)
                ->where('status', NightAuditRunStatusEnum::InProgress->value)
                ->lockForUpdate()
                ->orderBy('created_at')
                ->get();

            if ($activeRuns->count() > 1) {
                throw new RuntimeException(self::ERROR_MULTIPLE_ACTIVE_RUNS);
            }

            if ($activeRuns->count() === 1) {
                $existing = $activeRuns->first();
                if ((string) $existing->property_business_date_id !== (string) $businessDate->id) {
                    throw new RuntimeException(self::ERROR_CONTEXT_CONFLICT);
                }

                return $existing;
            }

            $attemptNumber = ((int) NightAuditRun::withoutGlobalScopes()
                ->where('property_business_date_id', $businessDate->id)
                ->max('attempt_number')) + 1;

            return NightAuditRun::create([
                'property_id' => $property->id,
                'property_business_date_id' => $businessDate->id,
                'business_date_snapshot' => $evidence['business_date'],
                'property_timezone_snapshot' => $evidence['property_timezone'],
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
}
