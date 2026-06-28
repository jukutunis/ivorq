<?php

namespace Modules\Operations\Inventory\ValueObjects;

use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Foundation\Audit\Models\AuditLog;

final readonly class InventoryReversalExecutionResult
{
    public string $outcome; // 'posted' or 'replayed'
    public InventoryTransaction $originalTransaction;
    public InventoryTransaction $reversalTransaction;
    public string $approvalReference;
    public string $idempotencyKey;
    public ?CostLedgerEntry $costLedgerEntry;
    public ?AuditLog $auditLog;

    public function __construct(
        string $outcome,
        InventoryTransaction $originalTransaction,
        InventoryTransaction $reversalTransaction,
        string $approvalReference,
        string $idempotencyKey,
        ?CostLedgerEntry $costLedgerEntry = null,
        ?AuditLog $auditLog = null
    ) {
        $this->outcome = $outcome;
        $this->originalTransaction = $originalTransaction;
        $this->reversalTransaction = $reversalTransaction;
        $this->approvalReference = $approvalReference;
        $this->idempotencyKey = $idempotencyKey;
        $this->costLedgerEntry = $costLedgerEntry;
        $this->auditLog = $auditLog;
    }
}
