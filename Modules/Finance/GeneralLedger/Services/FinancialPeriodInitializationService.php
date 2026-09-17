<?php

namespace Modules\Finance\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\CurrentBusinessDateService;
use Modules\Foundation\User\Models\User;
use RuntimeException;

class FinancialPeriodInitializationService
{
    public const ERROR_INITIALIZATION_NOT_ALLOWED_AFTER_HISTORY = 'FP_B4B_INITIALIZATION_NOT_ALLOWED_AFTER_HISTORY';

    public const ERROR_BUSINESS_DATE_CHANGED_OR_CLOSED = 'FP_B4B_BUSINESS_DATE_CHANGED_OR_CLOSED';

    public function __construct(
        private readonly FinancialPeriodAuthorizationService $authorization,
        private readonly CurrentBusinessDateService $currentBusinessDate,
    ) {}

    public function initialize(User $actor): FinancialPeriod
    {
        $authorizedProperty = $this->authorization->authorizeInitialization($actor);
        $resolvedBusinessDate = $this->currentBusinessDate->getActiveBusinessDate();

        return DB::transaction(function () use ($actor, $authorizedProperty, $resolvedBusinessDate): FinancialPeriod {
            $lockedBusinessDate = PropertyBusinessDate::withoutGlobalScopes()
                ->whereKey($resolvedBusinessDate->id)
                ->lockForUpdate()
                ->first();

            $revalidatedProperty = $this->authorization->authorizeInitialization($actor);

            if (
                ! $lockedBusinessDate
                || (string) $authorizedProperty->id !== (string) $revalidatedProperty->id
                || (string) $lockedBusinessDate->property_id !== (string) $revalidatedProperty->id
                || $lockedBusinessDate->status !== PropertyBusinessDateStatusEnum::Open
                || $lockedBusinessDate->is_open !== true
            ) {
                $this->rejectChangedBusinessDate();
            }

            $currentBusinessDate = $this->currentBusinessDate->getActiveBusinessDate();

            if (
                (string) $currentBusinessDate->id !== (string) $lockedBusinessDate->id
                || (string) $currentBusinessDate->property_id !== (string) $revalidatedProperty->id
                || $currentBusinessDate->status !== PropertyBusinessDateStatusEnum::Open
                || $currentBusinessDate->is_open !== true
            ) {
                $this->rejectChangedBusinessDate();
            }

            $year = $lockedBusinessDate->business_date->year;
            $month = $lockedBusinessDate->business_date->month;

            $history = FinancialPeriod::withoutGlobalScopes()
                ->where('property_id', $revalidatedProperty->id)
                ->lockForUpdate()
                ->orderBy('period_year')
                ->orderBy('period_month')
                ->orderBy('id')
                ->get();

            if ($history->isNotEmpty()) {
                if ($history->count() === 1 && $this->isExactRetry($history->first(), $year, $month)) {
                    return $history->first();
                }

                throw new RuntimeException(self::ERROR_INITIALIZATION_NOT_ALLOWED_AFTER_HISTORY);
            }

            $period = new FinancialPeriod;
            $period->forceFill([
                'property_id' => $revalidatedProperty->id,
                'period_year' => $year,
                'period_month' => $month,
                'status' => FinancialPeriodStatusEnum::Open,
                'opened_at' => CarbonImmutable::now('UTC'),
                'opened_by' => $actor->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'closing_snapshot_at' => null,
                'closed_at' => null,
                'closed_by' => null,
            ]);
            $period->save();

            return $period->fresh();
        }, 1);
    }

    private function isExactRetry(FinancialPeriod $period, int $year, int $month): bool
    {
        return $period->getRawOriginal('deleted_at') === null
            && $period->period_year === $year
            && $period->period_month === $month
            && $period->getRawOriginal('status') === FinancialPeriodStatusEnum::Open->value
            && $period->getRawOriginal('opened_at') !== null
            && $period->getRawOriginal('opened_by') !== null
            && $period->getRawOriginal('closed_at') === null
            && $period->getRawOriginal('closed_by') === null
            && $period->getRawOriginal('closing_snapshot_at') === null;
    }

    private function rejectChangedBusinessDate(): never
    {
        throw new RuntimeException(self::ERROR_BUSINESS_DATE_CHANGED_OR_CLOSED);
    }
}
