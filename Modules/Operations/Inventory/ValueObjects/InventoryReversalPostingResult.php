<?php

namespace Modules\Operations\Inventory\ValueObjects;

use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Operations\Inventory\Models\InventoryTransaction;

final readonly class InventoryReversalPostingResult
{
    public InventoryTransaction $originalTransaction;

    public InventoryTransaction $reversalTransaction;

    public ?string $costLedgerEntryId;

    public ?AuditLog $auditLog;

    public bool $replayed;

    public function __construct(
        InventoryTransaction $originalTransaction,
        InventoryTransaction $reversalTransaction,
        ?string $costLedgerEntryId = null,
        ?AuditLog $auditLog = null,
        bool $replayed = false,
    ) {
        $this->originalTransaction = $originalTransaction;
        $this->reversalTransaction = $reversalTransaction;
        $this->costLedgerEntryId = $costLedgerEntryId;
        $this->auditLog = $auditLog;
        $this->replayed = $replayed;
    }
}
