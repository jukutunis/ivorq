<?php
namespace Modules\Finance\CostControl\ValueObjects;
use InvalidArgumentException;
use DateTime;

class CostLedgerEntryIntent
{
    public readonly string $propertyId;
    public readonly string $sourceInventoryTransactionId;
    public readonly ?string $priorCostLedgerEntryId;
    public readonly string $entryType;
    public readonly string $idempotencyKey;
    public readonly int $entrySequence;
    public readonly string $currencyCode;
    public readonly AvcoDecimal $quantityDelta;
    public readonly AvcoDecimal $unitCost;
    public readonly AvcoDecimal $valueDelta;
    public readonly string $businessDate;
    public readonly string $occurredAt;
    public readonly ?string $originalBusinessDate;
    public readonly ?array $metadata;

    public function __construct(
        string $propertyId,
        string $sourceInventoryTransactionId,
        ?string $priorCostLedgerEntryId,
        string $entryType,
        string $idempotencyKey,
        int $entrySequence,
        string $currencyCode,
        AvcoDecimal $quantityDelta,
        AvcoDecimal $unitCost,
        AvcoDecimal $valueDelta,
        string $businessDate,
        string $occurredAt,
        ?string $originalBusinessDate = null,
        ?array $metadata = null
    ) {
        if (trim($propertyId) === '') throw new InvalidArgumentException("propertyId cannot be blank");
        if (trim($sourceInventoryTransactionId) === '') throw new InvalidArgumentException("sourceInventoryTransactionId cannot be blank");
        if ($entrySequence <= 0) throw new InvalidArgumentException("entrySequence must be positive");
        
        if (!in_array($entryType, ['receipt', 'issue', 'adjustment', 'transfer', 'correction', 'reversal'], true)) {
            throw new InvalidArgumentException("unknown entry type");
        }
        
        if (trim($idempotencyKey) === '') throw new InvalidArgumentException("idempotencyKey cannot be blank");
        
        if (!preg_match('/^[A-Z]{3}$/', $currencyCode)) {
            throw new InvalidArgumentException("currencyCode must be exactly uppercase [A-Z]{3}");
        }

        if (!($d = DateTime::createFromFormat('Y-m-d', $businessDate)) || $d->format('Y-m-d') !== $businessDate) {
            throw new InvalidArgumentException("businessDate must be a valid calendar date");
        }

        if ($originalBusinessDate !== null && (!($d = DateTime::createFromFormat('Y-m-d', $originalBusinessDate)) || $d->format('Y-m-d') !== $originalBusinessDate)) {
            throw new InvalidArgumentException("originalBusinessDate must be a valid calendar date");
        }

        if (trim($occurredAt) === '' || !strtotime($occurredAt)) {
            throw new InvalidArgumentException("occurredAt must be a valid parseable date-time");
        }

        $this->propertyId = $propertyId;
        $this->sourceInventoryTransactionId = $sourceInventoryTransactionId;
        $this->priorCostLedgerEntryId = $priorCostLedgerEntryId;
        $this->entryType = $entryType;
        $this->idempotencyKey = $idempotencyKey;
        $this->entrySequence = $entrySequence;
        $this->currencyCode = $currencyCode;
        $this->quantityDelta = $quantityDelta;
        $this->unitCost = $unitCost;
        $this->valueDelta = $valueDelta;
        $this->businessDate = $businessDate;
        $this->occurredAt = $occurredAt;
        $this->originalBusinessDate = $originalBusinessDate;
        $this->metadata = $metadata;
    }
}