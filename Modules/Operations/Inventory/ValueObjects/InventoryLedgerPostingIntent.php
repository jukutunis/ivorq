<?php

namespace Modules\Operations\Inventory\ValueObjects;

use Illuminate\Support\Carbon;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;

readonly class InventoryLedgerPostingIntent
{
    public function __construct(
        public string $propertyId,
        public string $itemId,
        public string $locationId,
        public string $businessDate,
        public Carbon $occurredAt,
        public string $sourceDocumentType,
        public string $sourceDocumentId,
        public string $sourceLineType,
        public string $sourceLineId,
        public string $movementRole,
        public string $idempotencyKey,
        public TransactionTypeEnum $transactionType,
        public string $quantityChange,
        public ?string $reference = null,
        public ?string $notes = null,
        public ?string $reversesInventoryTransactionId = null,
        public ?string $correctsInventoryTransactionId = null
    ) {
    }
}
