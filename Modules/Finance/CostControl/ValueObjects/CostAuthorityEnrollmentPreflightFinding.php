<?php

namespace Modules\Finance\CostControl\ValueObjects;

use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentPreflightFindingCode;

final class CostAuthorityEnrollmentPreflightFinding
{
    public function __construct(
        public readonly CostAuthorityEnrollmentPreflightFindingCode $code,
        public readonly string $detail = '',
    ) {}
}
