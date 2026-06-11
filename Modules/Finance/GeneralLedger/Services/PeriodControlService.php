<?php

namespace Modules\Finance\GeneralLedger\Services;

use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Exceptions\PeriodClosedException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
     * Checks if a period is open. If missing, auto-creates it as Open.
     */
    public function isOpen(string $propertyId, int $year, int $month): bool
    {
        $cacheKey = $this->getCacheKey($propertyId, $year, $month);

        $status = Cache::remember($cacheKey, now()->addDays(7), function () use ($propertyId, $year, $month) {
            $period = FinancialPeriod::where('property_id', $propertyId)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->first();

            if (!$period) {
                $period = FinancialPeriod::create([
                    'property_id' => $propertyId,
                    'period_year' => $year,
                    'period_month' => $month,
                    'status' => FinancialPeriodStatusEnum::Open,
                    'opened_at' => now(),
                ]);
            }

            return $period->status;
        });

        return $status === FinancialPeriodStatusEnum::Open || $status === FinancialPeriodStatusEnum::Reopened;
    }

    /**
     * Enforces that a period is open, throws exception if not.
     */
    public function enforceOpen(string $propertyId, int $year, int $month): void
    {
        if (!$this->isOpen($propertyId, $year, $month)) {
            throw new PeriodClosedException("The financial period {$year}-{$month} is closed or closing. No modifications are allowed.");
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
                ->firstOrFail();

            if ($period->status === FinancialPeriodStatusEnum::Closed) {
                throw new \Exception("Period is already closed.");
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
            throw new \Exception("Reopen requires a reason.");
        }

        DB::transaction(function () use ($propertyId, $year, $month, $userId, $reason) {
            $period = FinancialPeriod::where('property_id', $propertyId)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->firstOrFail();

            if ($period->status !== FinancialPeriodStatusEnum::Closed) {
                throw new \Exception("Only closed periods can be reopened.");
            }

            // Ensure it's the latest closed period
            $latestClosed = FinancialPeriod::where('property_id', $propertyId)
                ->where('status', FinancialPeriodStatusEnum::Closed)
                ->orderBy('period_year', 'desc')
                ->orderBy('period_month', 'desc')
                ->first();

            if ($latestClosed->id !== $period->id) {
                throw new \Exception("Only the most recently closed period can be reopened.");
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
        });
    }
}
