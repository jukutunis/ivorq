<?php

namespace Modules\Finance\CostControl\ValueObjects;

use InvalidArgumentException;

final readonly class CostDeliveryCutoverRequest
{
    public function __construct(
        public string $requestId,
        public string $propertyId,
        public string $itemId,
        public string $enrollmentGroupId,
        public string $targetFinancialPeriodId,
        public string $boundaryBusinessDate,
        public string $requestedBy,
        public string $approvedBy,
        public string $ownerApprovalReference,
    ) {
        foreach ([
            'requestId' => $requestId,
            'propertyId' => $propertyId,
            'itemId' => $itemId,
            'enrollmentGroupId' => $enrollmentGroupId,
            'targetFinancialPeriodId' => $targetFinancialPeriodId,
            'requestedBy' => $requestedBy,
            'approvedBy' => $approvedBy,
            'ownerApprovalReference' => $ownerApprovalReference,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Cost delivery cutover {$field} cannot be blank.");
            }
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $boundaryBusinessDate);
        if ($date === false || $date->format('Y-m-d') !== $boundaryBusinessDate) {
            throw new InvalidArgumentException('Cost delivery cutover boundaryBusinessDate must be YYYY-MM-DD.');
        }
        if ($requestedBy === $approvedBy) {
            throw new InvalidArgumentException('Cutover requester and approver must be different actors.');
        }
    }

    public function intentAttributes(): array
    {
        return [
            'property_id' => $this->propertyId,
            'item_id' => $this->itemId,
            'enrollment_group_id' => $this->enrollmentGroupId,
            'target_financial_period_id' => $this->targetFinancialPeriodId,
            'boundary_business_date' => $this->boundaryBusinessDate,
            'owner_approval_reference' => $this->ownerApprovalReference,
            'requested_by' => $this->requestedBy,
        ];
    }
}
