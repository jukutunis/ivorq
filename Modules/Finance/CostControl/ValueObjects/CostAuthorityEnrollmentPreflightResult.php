<?php

namespace Modules\Finance\CostControl\ValueObjects;

final class CostAuthorityEnrollmentPreflightResult
{
    /**
     * @param  CostAuthorityEnrollmentPreflightFinding[]  $factualFindings
     * @param  CostAuthorityEnrollmentPreflightFinding[]  $unresolvedActivationPrerequisites
     */
    public function __construct(
        public readonly string $enrollmentGroupId,
        public readonly array  $factualFindings,
        public readonly array  $unresolvedActivationPrerequisites,
    ) {}

    public function hasFactualBlockingFindings(): bool
    {
        return count($this->factualFindings) > 0;
    }

    public function hasUnresolvedActivationPrerequisites(): bool
    {
        return count($this->unresolvedActivationPrerequisites) > 0;
    }
}
