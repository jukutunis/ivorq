<?php

namespace Modules\Operations\Inventory\ValueObjects;

use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Operations\Inventory\Models\InventoryTransaction;

final readonly class InventoryReversalExecutionResult
{
    public string $outcome; // 'posted' or 'replayed'

    public InventoryTransaction $originalTransaction;

    public InventoryTransaction $reversalTransaction;

    public string $approvalReference;

    public string $idempotencyKey;

    public ?string $costLedgerEntryId;

    public ?AuditLog $auditLog;

    public function __construct(
        string $outcome,
        InventoryTransaction $originalTransaction,
        InventoryTransaction $reversalTransaction,
        string $approvalReference,
        string $idempotencyKey,
        ?string $costLedgerEntryId = null,
        ?AuditLog $auditLog = null
    ) {
        $this->outcome = $outcome;
        $this->originalTransaction = $originalTransaction;
        $this->reversalTransaction = $reversalTransaction;
        $this->approvalReference = $approvalReference;
        $this->idempotencyKey = $idempotencyKey;
        $this->costLedgerEntryId = $costLedgerEntryId;
        $this->auditLog = $auditLog;
    }
}
