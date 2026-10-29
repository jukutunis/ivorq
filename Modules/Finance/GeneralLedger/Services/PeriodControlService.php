<?php

namespace Modules\Finance\GeneralLedger\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Exceptions\PeriodClosedException;
use Modules\Finance\GeneralLedger\Models\FinancialPackageSnapshot;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;

class PeriodControlService
{
    /**
     * Cache key format: period:{property_id}:{year}:{month}
     */
    protected function getCacheKey(string $propertyId, int $year, int $month): string
    {
        return "period:{$propertyId}:{$year}:{$month}";
    }

    /**
     * Legacy compatibility check only. Missing periods fail closed and are never created here.
     */
    public function isOpen(string $propertyId, int $year, int $month): bool
    {
        $status = FinancialPeriod::where('property_id', $propertyId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->value('status');

        return $status === FinancialPeriodStatusEnum::Open->value
            || $status === FinancialPeriodStatusEnum::Reopened->value;
    }

    /**
     * Enforces that a period is open, throws exception if not.
     */
    public function enforceOpen(string $propertyId, int $year, int $month): void
    {
        if (! $this->isOpen($propertyId, $year, $month)) {
            throw new PeriodClosedException("The financial period {$year}-{$month} is missing, closed, or closing. No modifications are allowed.");
        }
    }

    /**
     * Closes a period.
     */
    public function close(string $propertyId, int $year, int $month, string $userId): void
    {
        DB::transaction(function () use ($propertyId, $year, $month, $userId) {
            $period = FinancialPeriod::where('property_id', $propertyId)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->lockForUpdate()
                ->firstOrFail();

            if ($period->status === FinancialPeriodStatusEnum::Closed) {
                throw new \Exception('Period is already closed.');
            }

            $period->update([
                'status' => FinancialPeriodStatusEnum::Closed,
                'closing_snapshot_at' => now(),
                'closed_at' => now(),
                'closed_by' => $userId,
            ]);

            Cache::forget($this->getCacheKey($propertyId, $year, $month));
        });
    }

    /**
     * Reopens the latest closed period.
     */
    public function reopen(string $propertyId, int $year, int $month, string $userId, string $reason): void
    {
        if (empty(trim($reason))) {
            throw new \Exception('Reopen requires a reason.');
        }

        DB::transaction(function () use ($propertyId, $year, $month, $userId) {
            $period = FinancialPeriod::where('property_id', $propertyId)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->lockForUpdate()
                ->firstOrFail();

            if ($period->status !== FinancialPeriodStatusEnum::Closed) {
                throw new \Exception('Only closed periods can be reopened.');
            }

            // Ensure it's the latest closed period
            $latestClosed = FinancialPeriod::where('property_id', $propertyId)
                ->where('status', FinancialPeriodStatusEnum::Closed)
                ->orderBy('period_year', 'desc')
                ->orderBy('period_month', 'desc')
                ->first();

            if ($latestClosed->id !== $period->id) {
                throw new \Exception('Only the most recently closed period can be reopened.');
            }

            $period->update([
                'status' => FinancialPeriodStatusEnum::Reopened,
                'opened_at' => now(),
                'opened_by' => $userId,
                'closed_at' => null,
                'closed_by' => null,
                'closing_snapshot_at' => null,
            ]);

            Cache::forget($this->getCacheKey($propertyId, $year, $month));

            if (Cache::supportsTags()) {
                Cache::tags(["reporting:{$propertyId}"])->flush();
            }

            // BR-014: Reopened periods invalidate snapshots.
            FinancialPackageSnapshot::where('property_id', $propertyId)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->forceDelete();
        });
    }
}
