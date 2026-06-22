<?php
namespace Modules\Finance\CostControl\ValueObjects;
use InvalidArgumentException;

class ApprovedInventoryEvidence
{
    public readonly string $sourceInventoryTransactionId;
    public readonly string $sourceTransactionReference;
    public readonly string $propertyId;
    public readonly string $itemId;
    public readonly string $valuationScope;
    public readonly string $currencyCode;
    public readonly string $sourceBusinessDate;
    public readonly string $occurredAt;
    public readonly string $eventType;
    public readonly AvcoDecimal $quantityDelta;
    public readonly ?AvcoDecimal $approvedValuationBasis;
    public readonly ?TransferValuationContext $transferContext;
    public readonly string $idempotencyKey;
    public readonly int $entrySequence;
    public readonly bool $isExplicitlyApproved;
    public readonly ?string $originalBusinessDate;
    public readonly ?array $metadata;

    public function __construct(
        string $sourceInventoryTransactionId,
        string $sourceTransactionReference,
        string $propertyId,
        string $itemId,
        string $valuationScope,
        string $currencyCode,
        string $sourceBusinessDate,
        string $occurredAt,
        string $eventType,
        AvcoDecimal $quantityDelta,
        ?AvcoDecimal $approvedValuationBasis,
        ?TransferValuationContext $transferContext,
        string $idempotencyKey,
        int $entrySequence,
        bool $isExplicitlyApproved,
        ?string $originalBusinessDate = null,
        ?array $metadata = null
    ) {
        if (empty($sourceInventoryTransactionId)) throw new InvalidArgumentException("sourceInventoryTransactionId cannot be empty");
        if (empty($sourceTransactionReference)) throw new InvalidArgumentException("sourceTransactionReference cannot be empty");
        if (empty($propertyId)) throw new InvalidArgumentException("propertyId cannot be empty");
        if (empty($itemId)) throw new InvalidArgumentException("itemId cannot be empty");
        if (empty($valuationScope)) throw new InvalidArgumentException("valuationScope cannot be empty");
        if (empty($idempotencyKey)) throw new InvalidArgumentException("idempotencyKey cannot be empty");
        if ($entrySequence <= 0) throw new InvalidArgumentException("entrySequence must be positive");
        if (!in_array($eventType, ['receipt', 'issue', 'positive_adjustment', 'negative_adjustment', 'transfer'], true)) {
            throw new InvalidArgumentException("Unknown event type: $eventType");
        }
        if (empty($currencyCode) || strlen($currencyCode) !== 3) throw new InvalidArgumentException("currencyCode must be a 3-character string");
        if (empty($sourceBusinessDate)) throw new InvalidArgumentException("sourceBusinessDate cannot be empty");
        if (empty($occurredAt)) throw new InvalidArgumentException("occurredAt cannot be empty");
        $this->sourceInventoryTransactionId = $sourceInventoryTransactionId;
        $this->sourceTransactionReference = $sourceTransactionReference;
        $this->propertyId = $propertyId;
        $this->itemId = $itemId;
        $this->valuationScope = $valuationScope;
        $this->currencyCode = $currencyCode;
        $this->sourceBusinessDate = $sourceBusinessDate;
        $this->occurredAt = $occurredAt;
        $this->eventType = $eventType;
        $this->quantityDelta = $quantityDelta;
        $this->approvedValuationBasis = $approvedValuationBasis;
        $this->transferContext = $transferContext;
        $this->idempotencyKey = $idempotencyKey;
        $this->entrySequence = $entrySequence;
        $this->isExplicitlyApproved = $isExplicitlyApproved;
        $this->originalBusinessDate = $originalBusinessDate;
        $this->metadata = $metadata;
    }
}