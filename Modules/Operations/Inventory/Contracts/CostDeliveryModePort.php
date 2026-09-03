<?php

namespace Modules\Operations\Inventory\Contracts;

use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;

interface CostDeliveryModePort
{
    public function isEnrolled(string $propertyId, string $itemId): bool;

    public function lockForDocumentMutation(string $propertyId, string $itemId): void;

    public function resolveForPosting(
        string $propertyId,
        string $itemId,
        string $locationId
    ): CostDeliveryPostingDecision;
}
