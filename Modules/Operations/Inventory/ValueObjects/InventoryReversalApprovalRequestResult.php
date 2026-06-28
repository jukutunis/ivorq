<?php

namespace Modules\Operations\Inventory\ValueObjects;

use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Foundation\Approval\Models\ApprovalRequest;

final readonly class InventoryReversalApprovalRequestResult
{
    public string $outcome; // 'created' or 'replayed'
    public InventoryTransaction $originalTransaction;
    public ApprovalRequest $approvalRequest;
    public string $idempotencyKey;

    public function __construct(
        string $outcome,
        InventoryTransaction $originalTransaction,
        ApprovalRequest $approvalRequest,
        string $idempotencyKey
    ) {
        $this->outcome = $outcome;
        $this->originalTransaction = $originalTransaction;
        $this->approvalRequest = $approvalRequest;
        $this->idempotencyKey = $idempotencyKey;
    }
}
