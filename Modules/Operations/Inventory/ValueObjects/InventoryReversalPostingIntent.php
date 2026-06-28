<?php

namespace Modules\Operations\Inventory\ValueObjects;

use InvalidArgumentException;

final readonly class InventoryReversalPostingIntent
{
    public string $originalTransactionId;
    public string $idempotencyKey;
    public string $actorId;
    public string $approvalReference;
    public string $reversalReason;

    public function __construct(
        string $originalTransactionId,
        string $idempotencyKey,
        string $actorId,
        string $approvalReference,
        string $reversalReason
    ) {
        if (trim($originalTransactionId) === '') {
            throw new InvalidArgumentException('originalTransactionId cannot be blank.');
        }
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('idempotencyKey cannot be blank.');
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

        $this->originalTransactionId = $originalTransactionId;
        $this->idempotencyKey = $idempotencyKey;
        $this->actorId = $actorId;
        $this->approvalReference = $approvalReference;
        $this->reversalReason = $reversalReason;
    }
}
