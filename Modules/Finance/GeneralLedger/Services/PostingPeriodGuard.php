<?php

namespace Modules\Finance\GeneralLedger\Services;

use Shared\Services\CurrentPropertyService;
use Modules\Foundation\Property\Services\CurrentBusinessDateService;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\ValueObjects\PostingPeriodGuardResult;
use Modules\Finance\GeneralLedger\Exceptions\FinancialPeriodMissingException;
use Modules\Finance\GeneralLedger\Exceptions\FinancialPeriodNotOpenException;
use Modules\Finance\GeneralLedger\Exceptions\FinancialPeriodAmbiguousException;
use Modules\Finance\GeneralLedger\Exceptions\FinancialPeriodInvalidStateException;
use ValueError;
use TypeError;

class PostingPeriodGuard
{
    public function __construct(
        private readonly CurrentPropertyService $currentPropertyService,
        private readonly CurrentBusinessDateService $currentBusinessDateService
    ) {}

    protected function resolveCandidates(string $propertyId, int $year, int $month)
    {
        return FinancialPeriod::where('property_id', $propertyId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->take(2)
            ->get();
    }

    public function assertPostingAllowed(): PostingPeriodGuardResult
    {
        // Must resolve through CurrentPropertyService. 
        // We call CurrentBusinessDateService which will enforce PropertyContext itself.
        $businessDate = $this->currentBusinessDateService->getActiveBusinessDate();
        
        $date = $businessDate->business_date;
        $year = $date->year;
        $month = $date->month;

        // Query active FinancialPeriod under current Property and derived year/month.
        // Retrieve max 2 to detect ambiguity defensively without throwing raw DB errors if they duplicate.
        $periods = $this->resolveCandidates($businessDate->property_id, $year, $month);

        if ($periods->isEmpty()) {
            throw new FinancialPeriodMissingException();
        }

        if ($periods->count() > 1) {
            throw new FinancialPeriodAmbiguousException();
        }

        $period = $periods->first();

        try {
            $statusRaw = $period->getRawOriginal('status');
            $status = FinancialPeriodStatusEnum::from($statusRaw);
        } catch (ValueError | TypeError $e) {
            throw new FinancialPeriodInvalidStateException();
        }

        if ($status !== FinancialPeriodStatusEnum::Open) {
            throw new FinancialPeriodNotOpenException();
        }

        return new PostingPeriodGuardResult(
            propertyId: $businessDate->property_id,
            financialPeriodId: $period->id,
            businessDate: $date,
            periodYear: $year,
            periodMonth: $month
        );
    }
}
