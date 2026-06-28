<?php

namespace Modules\Operations\Inventory\ValueObjects;

use InvalidArgumentException;

final readonly class InventoryReversalExecutionIntent
{
    public string $originalTransactionId;
    public string $actorId;
    public string $approvalReference;
    public string $reversalReason;
    public string $idempotencyKey;

    public function __construct(
        string $originalTransactionId,
        string $actorId,
        string $approvalReference,
        string $reversalReason,
        string $idempotencyKey
    ) {
        if (trim($originalTransactionId) === '') {
            throw new InvalidArgumentException('originalTransactionId cannot be blank.');
        }
        if (trim($actorId) === '') {
            throw new InvalidArgumentException('actorId cannot be blank.');
        }
        if (trim($approvalReference) === '') {
            throw new InvalidArgumentException('approvalReference cannot be blank.');
        }
        if (trim($reversalReason) === '') {
            throw new InvalidArgumentException('reversalReason cannot be blank.');
        }
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('idempotencyKey cannot be blank.');
        }

        $this->originalTransactionId = $originalTransactionId;
        $this->actorId = $actorId;
        $this->approvalReference = $approvalReference;
        $this->reversalReason = $reversalReason;
        $this->idempotencyKey = $idempotencyKey;
    }
}
