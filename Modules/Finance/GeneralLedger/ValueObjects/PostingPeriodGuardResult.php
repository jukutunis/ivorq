<?php

namespace Modules\Finance\GeneralLedger\ValueObjects;

use Carbon\Carbon;

readonly class PostingPeriodGuardResult
{
    public function __construct(
        public string $propertyId,
        public string $financialPeriodId,
        public Carbon $businessDate,
        public int $periodYear,
        public int $periodMonth,
    ) {}
}
