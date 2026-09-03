<?php

namespace Modules\Operations\Inventory\Contracts;

interface SynchronousCostValuationPort
{
    public function applyReceipt(string $sourceInventoryTransactionId): string;

    public function applyIssue(string $sourceInventoryTransactionId): string;

    public function applyAdjustment(string $sourceInventoryTransactionId): string;

    /** @return array{outbound:string,inbound:string} */
    public function applyTransfer(
        string $outboundInventoryTransactionId,
        string $inboundInventoryTransactionId,
    ): array;

    public function applyReversal(
        string $reversalInventoryTransactionId,
        string $originalInventoryTransactionId,
        string $reversalReason,
        string $approvalReference,
    ): string;
}
