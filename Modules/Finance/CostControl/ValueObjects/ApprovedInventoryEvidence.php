<?php
namespace Modules\Finance\CostControl\ValueObjects;
use InvalidArgumentException;
use DateTime;

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
    public readonly string $approvalStatus;
    public readonly ?string $approvalReference;
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
        string $approvalStatus,
        ?string $approvalReference = null,
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
        
        if (!preg_match('/^[A-Z]{3}$/', $currencyCode)) {
            throw new InvalidArgumentException("currencyCode must be exactly uppercase [A-Z]{3}");
        }

        if (!($d = DateTime::createFromFormat('Y-m-d', $sourceBusinessDate)) || $d->format('Y-m-d') !== $sourceBusinessDate) {
            throw new InvalidArgumentException("sourceBusinessDate must be a valid calendar date");
        }

        if ($originalBusinessDate !== null && (!($d = DateTime::createFromFormat('Y-m-d', $originalBusinessDate)) || $d->format('Y-m-d') !== $originalBusinessDate)) {
            throw new InvalidArgumentException("originalBusinessDate must be a valid calendar date");
        }

        if (!strtotime($occurredAt)) {
            throw new InvalidArgumentException("occurredAt must be a valid parseable date-time");
        }

        if (!in_array($approvalStatus, ['approved', 'pending', 'rejected'], true)) {
            throw new InvalidArgumentException("Unknown approvalStatus: $approvalStatus");
        }

        if ($approvalStatus === 'approved' && empty($approvalReference)) {
            throw new InvalidArgumentException("approved status requires non-empty approvalReference");
        }

        if (($approvalStatus === 'pending' || $approvalStatus === 'rejected') && !empty($approvalReference)) {
            throw new InvalidArgumentException("pending/rejected status must not carry approvalReference");
        }

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
        $this->approvalStatus = $approvalStatus;
        $this->approvalReference = $approvalReference;
        $this->isExplicitlyApproved = ($approvalStatus === 'approved' && !empty($approvalReference));
        $this->originalBusinessDate = $originalBusinessDate;
        $this->metadata = $metadata;
    }
}