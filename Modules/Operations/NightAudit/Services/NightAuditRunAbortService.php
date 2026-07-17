<?php

namespace Modules\Operations\NightAudit\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\User\Models\User;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Modules\Operations\NightAudit\Models\NightAuditRun;
use RuntimeException;

class NightAuditRunAbortService
{
    public const ERROR_INVALID_REASON = 'NA_A1_INVALID_ABORT_REASON';
    public const ERROR_NO_ACTIVE_RUN = 'NA_A1_NO_ACTIVE_RUN';
    public const ERROR_MULTIPLE_ACTIVE_RUNS = 'NA_A1_MULTIPLE_ACTIVE_RUNS';
    public const ERROR_CONTEXT_CONFLICT = 'NA_A1_ACTIVE_RUN_CONTEXT_CONFLICT';
    public const ERROR_INVALID_BUSINESS_DATE = 'NA_A1_INVALID_BUSINESS_DATE_PROJECTION';

    public function __construct(
        private readonly NightAuditAuthorizationService $authorization,
        private readonly NightAuditBusinessDateDependencyService $businessDateDependency,
    ) {}

    public function abort(User $actor, string $reason): NightAuditRun
    {
        $reason = $this->sanitizeReason($reason);
        $this->authorization->authorizeAbort($actor);
        $this->businessDateDependency->project($actor);

        return DB::transaction(function () use ($actor, $reason): NightAuditRun {
            $property = $this->authorization->authorizeAbort($actor);
            $evidence = $this->businessDateDependency->project($actor);

            if (($evidence['status'] ?? null) !== NightAuditBusinessDateDependencyService::STATUS_OPEN) {
                throw new DomainException(self::ERROR_INVALID_BUSINESS_DATE);
            }

            DB::table('properties')
                ->where('id', $property->id)
                ->lockForUpdate()
                ->first();

            DB::table('property_business_dates')
                ->where('id', $evidence['property_business_date_id'])
                ->where('property_id', $property->id)
                ->lockForUpdate()
                ->first();

            $activeRuns = NightAuditRun::withoutGlobalScopes()
                ->where('property_id', $property->id)
                ->where('status', NightAuditRunStatusEnum::InProgress->value)
                ->lockForUpdate()
                ->orderBy('created_at')
                ->get();

            if ($activeRuns->count() === 0) {
                throw new RuntimeException(self::ERROR_NO_ACTIVE_RUN);
            }

            if ($activeRuns->count() > 1) {
                throw new RuntimeException(self::ERROR_MULTIPLE_ACTIVE_RUNS);
            }

            $run = $activeRuns->first();
            if ((string) $run->property_business_date_id !== (string) $evidence['property_business_date_id']) {
                throw new RuntimeException(self::ERROR_CONTEXT_CONFLICT);
            }

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

    private function sanitizeReason(string $reason): string
    {
        $reason = trim($reason);

        if (strlen($reason) < 10 || strlen($reason) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
            throw new DomainException(self::ERROR_INVALID_REASON);
        }

        return $reason;
    }
}
