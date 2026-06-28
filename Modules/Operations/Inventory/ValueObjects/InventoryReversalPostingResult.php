<?php

namespace Modules\Operations\Inventory\ValueObjects;

use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Foundation\Audit\Models\AuditLog;

final readonly class InventoryReversalPostingResult
{
    public InventoryTransaction $originalTransaction;
    public InventoryTransaction $reversalTransaction;
    public ?CostLedgerEntry $costLedgerEntry;
    public ?AuditLog $auditLog;

    public function __construct(
        InventoryTransaction $originalTransaction,
        InventoryTransaction $reversalTransaction,
        ?CostLedgerEntry $costLedgerEntry = null,
        ?AuditLog $auditLog = null
    ) {
        $this->originalTransaction = $originalTransaction;
        $this->reversalTransaction = $reversalTransaction;
        $this->costLedgerEntry = $costLedgerEntry;
        $this->auditLog = $auditLog;
    }
}
