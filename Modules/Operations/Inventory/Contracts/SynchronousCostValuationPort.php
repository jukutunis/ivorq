<?php

namespace Modules\Operations\Inventory\Contracts;

interface SynchronousCostValuationPort
{
    public function applyReversal(
        string $reversalInventoryTransactionId,
        string $originalInventoryTransactionId,
        string $reversalReason,
        string $approvalReference,
    ): string;
}
