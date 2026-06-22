<?php
namespace Modules\Finance\CostControl\ValueObjects;
use InvalidArgumentException;
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
        if (empty($propertyId)) throw new InvalidArgumentException("propertyId cannot be empty");
        if (empty($sourceInventoryTransactionId)) throw new InvalidArgumentException("sourceInventoryTransactionId cannot be empty");
        if ($entrySequence <= 0) throw new InvalidArgumentException("entrySequence must be positive");
        if (empty($entryType)) throw new InvalidArgumentException("entryType cannot be empty");
        if (empty($idempotencyKey)) throw new InvalidArgumentException("idempotencyKey cannot be empty");
        if (empty($currencyCode) || strlen($currencyCode) !== 3) throw new InvalidArgumentException("currencyCode must be a 3-character string");
        if (empty($businessDate)) throw new InvalidArgumentException("businessDate cannot be empty");
        if (empty($occurredAt)) throw new InvalidArgumentException("occurredAt cannot be empty");

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